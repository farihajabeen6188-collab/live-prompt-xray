# Feedback on Symfony AI 1.0.0-BETA1 and PHP MCP SDK

Notes collected while building Live Prompt X-Ray: instrumentation hook points, streaming APIs,
MCP transport tapping, and anything that was hard to observe from the outside.
Versions: `symfony/ai-*` 1.0.0-BETA1, `symfony/mcp-bundle` 1.0.0-BETA1, `mcp/sdk` 0.9.0, Symfony 7.4, PHP 8.4.

## What worked well (hook points)
- **Platform**: `DeferredResult::onConvert()` / `onError()` plus `StreamResult::addListener()` are a clean,
  public way to observe a model turn without touching the result: X-Ray sees every `TextDelta`,
  `ToolCallComplete` and `TokenUsage` delta. `Provider` also dispatches `InvocationEvent`/`ResultEvent`,
  but only when the provider got an event dispatcher, and a custom `PlatformInterface` gets none of it,
  so decoration is the portable hook.
- **Agent**: `Execution` is iterable and accepts a factory closure, so a decorator can pass every update
  through and still notice the end of the run.
- **Toolbox**: decorating `ai.toolbox.<agent>` at priority 0 sits inside the `FaultTolerantToolbox`
  (-1024), so failures are observed as exceptions before they become messages for the model.
- **MCP client**: the SDK's `Protocol` hands every outgoing frame to `TransportInterface::send()` and every
  incoming one to the `onMessage()` callback, so a transport decorator sees the real JSON-RPC wire frames.
- **Tags** (`ai.agent`, `ai.platform`, `ai.toolbox`, `ai.retriever`, `mcp.client.connection`) made a
  generic compiler pass possible.

## 2026-11-19 Installation: `composer require symfony/ai-bundle` installs 0.15, not 1.0 beta
- What I tried: `composer require symfony/ai-bundle symfony/ai-agent symfony/ai-platform symfony/ai-store symfony/mcp-bundle`
  in a fresh Symfony 7.4 skeleton with `minimum-stability: beta`.
- What happened: with `prefer-stable: true` (the skeleton default), Composer picks `v0.15.0` (stable) over
  `v1.0.0-BETA1`. Nothing warns that the docs describe the 1.0 API.
- Workaround: require `"symfony/ai-bundle:^1.0@beta"` (and the same for each `symfony/ai-*` and `symfony/mcp-bundle` package).
- Upstream issue: not opened.

## 2026-11-19 MCP: the 2026-07-28 revision has no `initialize` handshake, and no session
- What I tried: capture the `initialize`, `tools/list`, `tools/call` frames the hackathon brief expects.
- What happened: `mcp/sdk` 0.9 negotiates the 2026-07-28 revision, which opens with `server/discover`
  and carries protocol version and client info in `params._meta` of every request. The server side is
  stateless: a tool that injects `RequestContext` gets a new session per call, so per-session state
  (our "fail on the first call, succeed on retry" demo tool) silently never recovers.
- Workaround: the UI shows whatever handshake is on the wire; the flaky tool keys its state on time instead.
  Pinning `mcp.clients.<name>.protocol_version` to an older revision brings `initialize` back.
- Suggestion: a sentence in the mcp-bundle docs that sessions are per request on the modern revision.

## 2026-11-19 Store: `Retriever` silently switches to a hybrid query
- What I tried: `new Retriever($inMemoryStore, $vectorizer)` and `retrieve($q, ['maxItems' => 3])`.
- What happened: because the in-memory store supports `HybridQuery`, the retriever uses it with a default
  `semanticRatio` of 0.5: cosine scores are halved, and substring keyword matches (with a `null` score)
  are appended after the vector hits, beyond `maxItems`. A store decorator only sees the vector, not the
  text, so the retriever is the right hook for tracing, but its results are hard to explain to users.
- Workaround: a small `RetrieverInterface` that issues a plain `VectorQuery` (`app/src/Rag/DocsRetriever.php`).
- Suggestion: make the query type an explicit option, or document the hybrid fallback on `Retriever`.

## 2026-11-19 Agent: `getModel()` is not on `AgentInterface`
- What happened: `Agent::getModel()` exists, but `AgentInterface` (and `TraceableAgent`) don't expose it,
  so a decorator has to `method_exists()` to label a run with its model.

## 2026-11-19 Toolbox: the MCP toolbox prefixes tool names by default
- What happened: with `tools.services: [{ mcp_server: 'client.server' }]` and no `prefix`, remote tools are
  exposed as `<server>_<tool>` (`demo_get_weather`). Fine, but correlating a `tools/call` frame
  (`get_weather`) with the agent's tool call (`demo_get_weather`) needs the prefix rule.

## 2026-11-19 FrankenPHP Mercure hub (not Symfony AI, noted for other hackathon teams)
- What I tried: the classic Caddyfile `mercure { publisher_jwt … anonymous }` with FrankenPHP 1.13.5.
- What happened: the hub refuses to start: `publisher_jwt` works only in compatibility mode.
- Workaround: add `protocol_version_compatibility 8`, which matches the HS256 tokens the Symfony Mercure client signs.

## 2026-11-19 Bundle config: env values for a compile-time switch
- What happened: `xray.enabled: '%env(bool:XRAY_ENABLED)%'` cannot be resolved in an extension
  ("Using a cast … is incompatible with resolution at compile time"); without a cast it works with
  `resolveEnvPlaceholders($value, true)`. Expected Symfony behaviour, noted because the error is confusing.
