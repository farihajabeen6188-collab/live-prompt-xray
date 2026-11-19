# Trace schema (v1)

Every event is JSON with these envelope fields:

```json
{ "v": 1, "runId": "run_3f2a9c0d1e4b5a6c7d8e", "seq": 17, "ts": 1243, "type": "tool.call.finished", "data": { } }
```
- `v`: envelope version, `1`.
- `runId`: `run_` followed by 8 to 40 letters or digits.
- `seq`: integer, starts at 1, +1 per event within a run. Clients re-order by `seq` and drop duplicates.
- `ts`: milliseconds since run start (monotonic clock).
- `type`: one of the types below. Unknown types must be ignored by clients (forward compatibility).

## Types and `data`

Fields marked *(added)* were not in the original brief; they are additive, so v1 readers that ignore unknown keys keep working.

| type | data |
|---|---|
| `run.started` | `{ "prompt": str, "agent": str, "model": str, "mode": "demo"\|"live", "scenario": str? }` |
| `prompt.sent` | `{ "messages": [ {role, content (redacted, may be truncated), "truncated": bool, "toolCallId"?: str} ], "tools": [ {name, description} ], "turn": int, "model": str (added) }` |
| `token.chunk` | `{ "turn": int, "text": str }` |
| `retrieval.hit` | `{ "store": str, "query": str, "hits": [ {id, score, snippet, "source": str? (added)} ], "durationMs": int }` (score: cosine distance as returned by the store, lower is closer) |
| `tool.call.requested` | `{ "callId": str, "name": str, "arguments": object, "turn": int }` |
| `tool.call.started` | `{ "callId": str, "name": str (added), "via": "local"\|"mcp", "server": str? }` |
| `tool.call.finished` | `{ "callId": str, "ok": bool, "result": any (redacted/truncated), "durationMs": int, "error": str? }` |
| `mcp.frame` | `{ "server": str, "direction": "out"\|"in", "kind": "request"\|"response"\|"notification"\|"error", "method": str?, "id": int\|str\|null, "payload": object (redacted), "correlatesTo": str? (callId), "latencyMs": int? (added, responses only) }` |
| `usage.reported` | `{ "turn": int, "inputTokens": int, "outputTokens": int, "estimatedCostUsd": number?, "durationMs": int (added, model turn duration), "estimated": bool (added, true when the provider reported no usage and X-Ray counted ~4 characters per token) }` |
| `run.error` | `{ "where": "platform"\|"tool"\|"mcp"\|"store"\|"agent", "message": str, "callId": str? (added) }` |
| `run.finished` | `{ "durationMs": int, "turns": int, "toolCalls": int, "inputTokens": int, "outputTokens": int, "estimatedCostUsd": number?, "finalText": str, "ok": bool (added) }` |

`estimatedCostUsd` is `null` when no price is configured for the model (`xray.prices`): X-Ray never invents a price.
A run whose tool failed but which still produced an answer finishes with `ok: true`; the failure stays visible
as `tool.call.finished` with `ok: false` and a `run.error`.

### MCP handshake
The frames are whatever the MCP client puts on the wire. With `mcp/sdk` 0.9 and protocol revision
2026-07-28 a connection opens with `server/discover` (no `initialize` / `notifications/initialized`);
older revisions show the classic `initialize` handshake.

## Trace file (export)
```json
{ "format": "xray-trace", "version": 1, "createdAt": "ISO-8601", "run": { "id": "...", "prompt": "...", "agent": "...", "model": "...", "mode": "...", "scenario": null, "startedAt": "ISO-8601", "status": "finished", "summary": {}, "imported": false }, "events": [ ...envelope events... ] }
```
Import validates `format`, the major `version` and every envelope, and rejects anything else with a clear error.
An imported run gets a new run id, so it never overwrites a recorded run.

## Rules
- Redact keys matching (case-insensitive, ignoring `-` and `_`, suffixes included) `api_key`, `apikey`,
  `authorization`, `token`, `secret`, `password` before storing or publishing; the list is configurable.
  `Bearer …` credentials and `sk-`/`hf_`/`ghp_`-style keys are masked inside any string.
- Truncate any single string over 8 KB (configurable) and set `truncated: true` on prompt messages.
- Correlate tool calls to MCP frames via `callId` -> `correlatesTo`.
