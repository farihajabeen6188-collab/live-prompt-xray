# MASTER PROMPT FOR CLAUDE CODE: build "Live Prompt X-Ray"

Paste everything below the line into Claude Code, run from an EMPTY project folder.
First copy `CLAUDE.md`, `docs/` and `skeleton/*` into that folder so Claude Code can read them.

---

You are building **Live Prompt X-Ray** for the **Symfony AI & MCP Hackathon 2026**.
Deadline: **22 Nov 2026, 18:00 CET**. Deliverables: a public repo anyone can clone and run, plus a 90-second video. Judges clone and run the repo, so setup MUST be one command.

## 1. What it is
A realtime visual debugger for Symfony AI agents. The user types a prompt, and while the agent works, the screen shows its mind live:
- a **graph** where nodes (User, Agent, Model, each Tool, each MCP server, Store/RAG) light up and animated packets travel along edges as things happen,
- a **token stream** of the model's answer, plus the full prompt/context actually sent,
- a **waterfall timeline** of every step with durations,
- an **MCP inspector** showing real JSON-RPC frames (`initialize`, `tools/list`, `tools/call`, results, errors),
- **meters**: latency, input/output tokens, tool-call count, estimated cost.

Every run is recorded as a portable **trace** that can be replayed at any speed, exported as JSON, and diffed against another run.

The real problem: agents are black boxes, and debugging them (why did it call that tool? what context did it see? why was it slow?) is painful. X-Ray is to Symfony AI what the Symfony Profiler is to HTTP requests.

It ships as a **reusable Symfony bundle** (`xray-bundle`) plus a **demo app**. Another developer must be able to add it to their own Symfony AI app in a few lines.

## 2. Judging criteria (design every decision around these)
1. Creativity: the visual IS the product; it must look stunning and clear.
2. Working functionality: a demo that really works, reproducible.
3. Effective use of Symfony AI and the PHP MCP SDK: instrument real Agent, Toolbox, Platform, Store and MCP traffic, no mocks pretending to be real.
4. Code quality: "code you would merge" (bundle structure, tests, static analysis).
The organizers also want beta feedback. Keep `docs/FEEDBACK.md` updated with anything confusing or broken in Symfony AI / PHP MCP SDK.

## 3. Hard rules
- **Do not guess APIs.** Symfony AI is `1.0.0-BETA1`; PHP MCP SDK (`mcp/sdk`) is ~0.9. Before using any class, interface, attribute or config key, read the installed code in `vendor/` and the repo docs. If reality differs from this prompt, follow reality and log it in `docs/FEEDBACK.md`.
- Stack: PHP 8.4, Symfony 7.4, Twig + Stimulus + Turbo, AssetMapper (no Node build step), SQLite for stored traces, Docker Compose. No external CDNs: vendor any JS locally via importmap.
- **Instrument by decoration, not by patching vendor.** Prefer these hook points, in this order, after checking what actually exists: (a) event dispatcher events if Symfony AI exposes them, (b) decorators around `PlatformInterface`, the Toolbox, and the Store, (c) Agent input/output processors. For MCP: tap JSON-RPC frames at the transport/handler level of the SDK or bundle (server side for the demo MCP server; client side if an MCP client bridge exists). If a hook is impossible, say so in FEEDBACK.md and pick the closest legitimate alternative.
- **Instrumentation must be safe**: if X-Ray fails or the hub is down, the agent run must continue unaffected. Never let recorder exceptions escape. Add a config switch to disable X-Ray entirely and a redaction hook (default redacts values for keys like `api_key`, `authorization`, `token`, `password`).
- **Realtime transport**: prefer Mercure (FrankenPHP's built-in hub in Docker) if it works cleanly with the Symfony Mercure bundle; otherwise Server-Sent Events from a streaming controller. Decide after a quick spike in Phase 0 and record the decision in `docs/ARCHITECTURE.md`. Events must reach the browser in well under 200 ms and arrive in order (sequence numbers; client re-orders and de-dupes).
- **Works with zero API keys**: implement `ScriptedPlatform` (a fake model implementing the platform interface, scripted turns including tool calls, streamed token chunks with realistic delays) for Demo Mode. Mark it clearly in the UI ("Demo mode: scripted model"). Real providers (Ollama / hosted) are enabled by env vars. Tool calls, MCP traffic and RAG in demo mode are REAL (real Toolbox, real MCP server, real Store with a small local corpus); only the model is scripted.
- Strict types, final classes, readonly, PHPStan level 8, PHP-CS-Fixer (Symfony rules), no dead code, no leftover TODOs, no secrets in git. Provide `.env.example`.
- Commit after every phase with a clear message.

## 4. Deliverable structure
Read `docs/ARCHITECTURE.md`, `docs/TRACE_SCHEMA.md`, `docs/UI_SPEC.md`, `docs/DEMO_SCENARIOS.md` before coding; they are the source of truth.

Monorepo:
- `lib/xray-bundle/` : the reusable bundle (recorder, decorators, event model, transports, redaction, storage interface, Twig/Stimulus UI, routes under `/_xray`, DI config, its own tests).
- `app/` : the demo Symfony app (agent with tools, demo MCP server, local RAG corpus, scenario picker).
- `docker-compose.yml`, `Makefile`, `README.md`, `docs/`.

## 5. Phases (in order; run the gate after each; do not continue if it fails)
**Phase 0: Bootstrap and spike.** Monorepo with path repository for the bundle, Symfony 7.4 app, Docker (FrankenPHP preferred), install Symfony AI + mcp-bundle + chosen realtime transport. Spike: push one fake event to the browser and render it. Gate: `make up` serves the app; the spike event appears live; decision on Mercure vs SSE logged.
**Phase 1: Event model and recorder.** Implement the trace schema (`docs/TRACE_SCHEMA.md`): typed event classes, `Recorder` with monotonically increasing sequence numbers and run IDs, `Redactor`, `TraceStore` (SQLite or file JSONL), safe-failure wrapper. Gate: unit tests for ordering, redaction, safe failure.
**Phase 2: Instrumentation.** Decorators/listeners for Platform (request, streamed chunks, usage, errors), Toolbox (tool requested, started, finished, failed, duration), Store (query, hits, scores), Agent run lifecycle. Gate: integration test runs an agent with `ScriptedPlatform` + a real tool and asserts the exact event sequence.
**Phase 3: MCP capture.** Demo MCP server via `mcp-bundle` exposing 4-5 tools (see DEMO_SCENARIOS). Capture JSON-RPC frames (direction, method, id, payload, latency, errors) as events and correlate each `tools/call` with the agent's tool call. If an MCP client bridge exists in Symfony AI, route the agent's tools through it so traffic is genuinely agent->MCP->server. Gate: test asserts frames for `initialize`, `tools/list`, `tools/call` appear and correlate.
**Phase 4: Realtime delivery.** Publish events live; `/_xray/runs/{id}/stream` for live; `/_xray/runs/{id}/replay?speed=` for stored runs. Ordering/de-dupe client logic. Gate: functional test connects a stream client and receives ordered events for a run.
**Phase 5: The UI.** Implement `docs/UI_SPEC.md`: graph, token stream, context/prompt inspector, waterfall, MCP inspector, meters, scenario picker, replay controls, export/import trace. Dark, crisp, 60fps animations (CSS/SVG, requestAnimationFrame); respect `prefers-reduced-motion`. Gate: manual check in Demo Mode across all scenarios + a UI smoke test.
**Phase 6: Diff and share.** Run diff (two traces side by side: changed prompt, changed tool calls, tokens, latency), JSON export/import. Gate: tests for diff logic.
**Phase 7: Bundle polish.** `composer require`-ready bundle: config reference, Flex-style recipe docs, Profiler-style toolbar link (stretch), disable switch, README with 5-line install into any Symfony AI app. Gate: install the bundle into a *fresh* skeleton app in a temp dir following only the README, and see a run live.
**Phase 8: Quality.** PHPStan 8, CS-Fixer, tests green, remove dead code. Gate: `make stan cs test` green.
**Phase 9: Docs and submission.** README (what/why, 60-second quickstart, demo vs real model, bundle install, architecture Mermaid diagram, trace schema link, security notes on redaction), FEEDBACK.md, VIDEO_SCRIPT.md adjusted to the real UI, license. Gate: fresh clone -> `docker compose up` -> live X-Ray works, exactly as README says.

## 6. Definition of done
- Fresh clone + one command = a live, impressive run in Demo Mode within 2 minutes.
- Real instrumentation of Agent, Toolbox, Platform, Store and MCP frames, with tests.
- Replay + export + diff work.
- Bundle installable into another app via README only.
- PHPStan 8, CS, tests green. README, FEEDBACK.md, VIDEO_SCRIPT.md complete.

## 7. Scope discipline
Priority order if time runs short: (1) live graph + token stream + waterfall, (2) MCP inspector, (3) replay + export, (4) meters + context inspector, (5) diff, (6) bundle polish extras. Never ship a broken (1) for the sake of (5).

Start now with Phase 0. First summarize your plan in 10 lines, then proceed phase by phase, reporting each gate result.
