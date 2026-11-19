# Architecture

## Monorepo tree (as built)

```
live-prompt-xray/
├── CLAUDE.md, README.md, LICENSE
├── Makefile, dev.ps1                   # dev.ps1 mirrors every make target on Windows
├── docker-compose.yml, Dockerfile      # FrankenPHP 1.13 (PHP 8.4) with its built-in Mercure hub
├── docker/                             # Caddyfile, php.ini, entrypoint (composer install on first boot)
├── phpstan.neon, .php-cs-fixer.dist.php
├── lib/xray-bundle/                    # the reusable bundle
│   ├── config/{services.php,routes.php}
│   ├── src/
│   │   ├── XRayBundle.php              # config tree, services, AssetMapper path, compiler pass
│   │   ├── DependencyInjection/InstrumentationPass.php   # decorates tagged services
│   │   ├── Event/                      # TraceEvent + one readonly class per type, Envelope
│   │   ├── Recorder/                   # Recorder (seq, ts, safe-failure), Redactor, RunContext, Run
│   │   ├── Instrumentation/
│   │   │   ├── TracingAgent.php        # run lifecycle (wraps the Execution)
│   │   │   ├── TracingPlatform.php     # prompt, stream, tool calls, usage (DeferredResult callbacks)
│   │   │   ├── TurnRecorder.php, TurnStreamListener.php
│   │   │   ├── TracingToolbox.php      # tool start/finish, local vs MCP
│   │   │   ├── TracingRetriever.php    # retrieval hits and scores
│   │   │   └── Mcp/{TappingTransport,McpToolDirectory}.php  # JSON-RPC frame tap on the client transport
│   │   ├── Transport/{EventPublisher,MercurePublisher,NullPublisher}.php
│   │   ├── Storage/{TraceStore,SqliteTraceStore,RunRecord}.php
│   │   ├── Replay/TraceFile.php        # export/import format
│   │   ├── Diff/TraceDiffer.php
│   │   ├── Preview/{PromptPreviewer,ToolPlanProvider}.php   # the typing preview
│   │   ├── Pricing/CostEstimator.php
│   │   └── Controller/{ConsoleController,StreamController,TraceController,PreviewController}.php
│   ├── templates/console.html.twig
│   ├── assets/                         # console.css, console.js, controllers/, lib/ (graph, trace,
│   │                                   # views, player, diff), vendor/stimulus.js
│   └── tests/{Unit,Integration,Support}
├── app/                                # the demo app
│   ├── config/packages/{ai,mcp,xray,mercure}.yaml
│   ├── src/
│   │   ├── Platform/{ScriptedPlatform,DemoPlanner,ScriptBook,ScriptedTurn,PassThroughConverter,
│   │   │             SwitchablePlatform,DemoPlanPreview}.php
│   │   ├── Model/{ModelSettings,ModelConnection}.php   # the model connected in the console
│   │   ├── Mcp/DemoTools.php           # 5 #[McpTool]s served at /mcp
│   │   ├── Demo/{WeatherService,NotesRepository,Calculator}.php
│   │   ├── Rag/{HashingVectorizer,DocsStoreFactory,DocsRetriever,DocsContextProcessor}.php
│   │   ├── Tool/SearchDocs.php         # a local (non-MCP) tool
│   │   └── Controller/{RunController,ModelController,HomeController}.php
│   ├── scripted/*.json                 # one script per scenario for the scripted model
│   ├── corpus/*.md                     # 10 Nimbus Cloud docs for RAG
│   ├── data/notes.json
│   └── tests/{Integration,Functional}
└── docs/
```

## Data flow

```mermaid
flowchart LR
  U[Console: prompt] -->|POST /demo/run with runId| RC[RunController]
  RC --> AG[TracingAgent → Symfony AI Agent]
  AG --> IP[DocsContextProcessor] --> RT[TracingRetriever → in-memory Store]
  AG --> PL[TracingPlatform → ScriptedPlatform or Ollama]
  AG --> TB[TracingToolbox → FaultTolerant / Chain toolbox]
  TB --> LT[search_docs: local tool] --> RT
  TB --> MT[McpToolbox → MCP client] --> TAP[TappingTransport] -->|HTTP JSON-RPC| MS[Demo MCP server /mcp]
  PL --> REC[Recorder: seq, ts, redact, safe-fail]
  TB --> REC
  RT --> REC
  TAP --> REC
  AG --> REC
  REC --> DB[(SQLite TraceStore)]
  REC --> PUB[MercurePublisher] --> HUB[FrankenPHP Mercure hub]
  HUB -->|EventSource, topic xray/runs/{runId}| UI[Console: graph, tokens, waterfall, inspector, meters]
  DB -->|/_xray/runs/{id}, /stream, /replay, /export, /diff| UI
```

## Key decisions
- **Decoration over patching.** `InstrumentationPass` decorates services by the tags Symfony AI and the MCP
  bundle already set: `ai.agent`, `ai.platform`, `ai.toolbox` (priority 0, inside the fault tolerant toolbox),
  `ai.retriever` and the transport of each `mcp.client.connection`. No vendor code is touched; the bundle works
  in any app that uses those bundles.
- **Observation, not interception.** The platform decorator registers `DeferredResult::onConvert()`/`onError()`
  callbacks and a `StreamResult` listener; it never rewrites a delta. The agent decorator passes every
  `Execution` update through unchanged.
- **Safe-failure.** `Recorder` catches every failure (store, transport, bugs in a payload) and logs a warning;
  so do the stream listener and the frame tap. Tests prove a dead hub and a failing store leave runs unchanged.
- **Order.** Events carry `runId`, `seq`, `ts` (monotonic ms from run start). The client buffers by `seq`, applies
  strictly in order and drops duplicates (two concurrent publishes arrived out of order in the Phase 0 spike).
- **Redaction before publish.** The `Redactor` rewrites payloads before storage and transport.
- **Realtime transport: Mercure** (decided 2026-11-19 after the Phase 0 spike). FrankenPHP ships the hub;
  `symfony/mercure-bundle` publishes to `http://localhost/.well-known/mercure`. Measured publish-to-render latency
  in the browser: 8–32 ms. The console subscribes once to the URI template `xray/runs/{runId}` and so follows every
  run, including runs started outside the console. The hub runs with `protocol_version_compatibility 8` because
  the Symfony Mercure client signs HS256 publisher JWTs. `transport: auto` falls back to **SSE from the trace
  store** (`/_xray/runs/{id}/stream`, 40 ms polling, `Last-Event-ID` resume) when MercureBundle is absent.
- **Replay on a virtual clock in the browser.** The console fetches a run's events and replays them through the
  same `Trace.push()` + render path as live events, which gives pause, speed and scrubbing. `/replay?speed=` is the
  same idea server-side, as SSE, for headless consumers.
- **Demo Mode.** Only the model is scripted: `ScriptedPlatform` is a real `PlatformInterface` that streams
  `TextDelta`s with realistic pacing and emits `ToolCallComplete`; its answers are templated on the *real* tool
  results. It reports no token usage, so the meters show X-Ray's estimate, labelled as such.
  Tools, the MCP client and server, RAG, the recorder and the UI are real.
- **RAG without API keys.** `HashingVectorizer` embeds text locally (feature hashing); the in-memory store computes
  real cosine distances. `DocsRetriever` issues a plain `VectorQuery` (Symfony's `Retriever` would switch to a
  hybrid query, see FEEDBACK.md).
- **MCP for real.** The demo agent reaches the demo MCP server through the MCP client bridge
  (`tools.services: [{ mcp_server: 'xray.demo' }]`): over HTTP in dev, over STDIO in tests (hermetic).
- **Frontend without a build.** The console is ES modules served by AssetMapper. Relative imports are resolved
  through an import map the console renders itself, and Stimulus is vendored in the bundle, so a host app needs
  no `importmap.php` or `controllers.json` change. Turbo is not used: the console is a single page.
  SVG/CSS animation, `requestAnimationFrame` batching, `prefers-reduced-motion` respected.
- **Pre-flight preview.** `PromptPreviewer` runs an agent's own input processors (wired per agent by
  `InstrumentationPass` from the `ai.agent.input_processor` tags) inside a *preview run*: `RunContext::beginPreview()`
  makes the `Recorder` keep events in memory instead of storing and publishing them, so the existing decorators
  report the retrievals and MCP traffic of the dry run. The console calls it on every keystroke (debounced, the
  previous request aborted) and draws a ghost graph of the planned path. Demo Mode's planner provides the exact
  tool plan through `ToolPlanProvider`.
- **Chat.** The console keeps the conversation and sends it as `history`; the run endpoint builds a `MessageBag`
  (history + new message). The trace and the preview show the conversation's share of the context.
- **Any model.** `SwitchablePlatform` (tagged `ai.platform`, so traced) answers with the scripted model or, once a
  model is connected in the console, with any OpenAI-compatible API through `Symfony\AI\Platform\Bridge\Generic\Factory`.
  `ModelOverrideInputProcessor` passes the chosen model name, so traces show it.
- **Disable switch.** `xray.enabled: false` (or `XRAY_ENABLED=0`, read when the container is built) removes all
  X-Ray services and decorators.
