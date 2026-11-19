# X-Ray bundle

A realtime visual debugger for [Symfony AI](https://symfony.com/doc/current/ai/index.html) agents and MCP:
watch every model turn, streamed token, tool call, retrieval and MCP JSON-RPC frame of your agent as it runs,
then replay, export and diff the recorded runs. Think Symfony Profiler, for agents.

Requires PHP 8.4, Symfony 7.4, `symfony/ai-bundle` 1.0 (beta) and AssetMapper.

## Install (5 lines)

```bash
composer require --dev xray/xray-bundle
```

```php
// config/bundles.php: development only, the console shows prompts and tool data
XRay\XRayBundle::class => ['dev' => true],
```

```yaml
# config/routes/xray.yaml
when@dev:
    xray:
        resource: '@XRayBundle/config/routes.php'

# config/packages/xray.yaml
when@dev:
    xray:
        transport: auto   # "mercure" when symfony/mercure-bundle is enabled, else "sse"
```

Open **`/_xray`** and run your agent (a request, a command, a worker): the console follows every run live.

While the package is not on Packagist, add the repository first:
`composer config repositories.xray path /path/to/live-prompt-xray/lib/xray-bundle` after cloning
https://github.com/farihajabeen6188-collab/live-prompt-xray (the bundle lives in a sub-directory of the repository).

## What gets recorded

Nothing in your code changes. A compiler pass decorates the services Symfony AI and the MCP bundle already tag:

| Tag | Decorator | Events |
|---|---|---|
| `ai.agent` | `TracingAgent` | `run.started`, `run.error`, `run.finished` |
| `ai.platform` | `TracingPlatform` | `prompt.sent` (exact messages + tool schemas), `token.chunk`, `tool.call.requested`, `usage.reported` |
| `ai.toolbox` | `TracingToolbox` | `tool.call.started`, `tool.call.finished` (result or error, duration, local or MCP) |
| `ai.retriever` | `TracingRetriever` | `retrieval.hit` (query, hits, scores) |
| `mcp.client.connection` | `TappingTransport` on the client transport | `mcp.frame`: every JSON-RPC frame, correlated with its tool call |

Platforms configured under `ai.platform` are tagged by the AI bundle. A platform service you define yourself
needs the tag: `tags: [{ name: 'ai.platform' }]`. The wire format is documented in
[TRACE_SCHEMA.md](../../docs/TRACE_SCHEMA.md).

Recording never breaks a run: every failure inside X-Ray (store, transport, a bug) is caught and logged as a warning.

## Pre-flight preview (while the user types)

`POST /_xray/preview {"prompt": "...", "history": [{role, content}], "agent": "name"?}` runs the agent's own
input processors (same services, same order) on the draft, without calling the model, inside a *preview run*:
the decorators record as usual but the events stay in memory, nothing is stored or published. It returns the
exact messages the model would receive, the tokens each processor added, the tool definitions, and the
retrievals and MCP traffic the dry run caused. The console calls it on every keystroke (debounced).

Mind processors with costs: a retrieval that calls a paid embedding API runs on every preview.

To show which tools the model will call, implement `XRay\Preview\ToolPlanProvider` (a rules-based or scripted
model can know; an LLM cannot without being called). Without one, tools are ranked by keyword match and the
console says so.

## Configuration reference

```yaml
xray:
    # false removes every X-Ray service and decorator. An env value is read when the container is built.
    enabled: true
    # "mercure": events are pushed through the Mercure hub (topic "xray/runs/{runId}").
    # "sse": the console streams events from the trace store (/_xray/runs/{id}/stream), no hub needed.
    # "auto" (default): mercure when MercureBundle is enabled, else sse.
    transport: auto
    # SQLite file holding the recorded runs.
    storage_path: '%kernel.project_dir%/var/xray/traces.sqlite'
    # Values under these keys never leave the process (case, "-" and "_" ignored, suffixes match:
    # "token" hides "access_token" but not "inputTokens"). Bearer credentials and sk-/hf_/ghp_ style
    # keys are masked inside any string.
    redact_keys: [api_key, apikey, authorization, token, secret, password]
    # Longer strings are truncated (and flagged) in traces.
    max_string_bytes: 8192
    # The context window the typing preview measures the prompt against.
    context_window: 128000
    # Optional: an endpoint of your app that runs a prompt, to get a prompt box in the console.
    # GET returns {mode, model, scenarios: [{id, title, prompt, description}]};
    # POST {"prompt": "...", "runId": "run_..."} runs the agent after RunContext::prepare($runId).
    run_url: null
    # USD per 1M tokens, keyed by model name. Models without a price show no cost estimate.
    prices:
        gpt-4o-mini: { input: 0.15, output: 0.6 }
```

## HTTP endpoints

| Route | Purpose |
|---|---|
| `GET /_xray` | The console |
| `GET /_xray/runs` | Recorded runs (JSON) |
| `GET /_xray/runs/{id}` | One run with its events |
| `GET /_xray/runs/{id}/stream` | Server-Sent Events while the run is recorded (resumes with `Last-Event-ID`) |
| `GET /_xray/runs/{id}/replay?speed=2` | Server-Sent Events replay with the original timing (0.25x to 8x) |
| `GET /_xray/runs/{id}/export` | Download the trace file |
| `POST /_xray/import` | Import a trace file (the run gets a new id) |
| `POST /_xray/preview` | Pre-flight X-Ray of a draft prompt (nothing recorded) |
| `GET /_xray/diff?a={id}&b={id}` | Compare two runs |

## Security

- The console has no authentication and shows prompts, tool arguments and results. Register the bundle
  for `dev` only, or protect `^/_xray` with your firewall.
- Redaction runs before events are stored or published. Review `redact_keys` for your own secret names.
- With Mercure, subscriptions to `xray/runs/{runId}` are anonymous in the demo hub configuration; use
  `sse`, or private updates, on shared machines.

## Tests

The bundle's tests live in `tests/` (unit and integration with a real `Agent` and `Toolbox`, the model
scripted through Symfony AI's `MockPlatformFactory`). In this repository: `make test` / `./dev.ps1 test`.
