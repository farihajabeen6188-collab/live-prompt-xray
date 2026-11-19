# Trace schema (v1)

Every event is JSON with these envelope fields:

```json
{ "v": 1, "runId": "run_01H...", "seq": 17, "ts": 1243, "type": "tool.call.finished", "data": { } }
```
- `seq`: integer, starts at 1, +1 per event within a run.
- `ts`: milliseconds since run start (monotonic).
- `type`: one of the types below. Unknown types must be ignored by clients (forward compatibility).

## Types and `data`

| type | data |
|---|---|
| `run.started` | `{ "prompt": str, "agent": str, "model": str, "mode": "demo"\|"live", "scenario": str? }` |
| `prompt.sent` | `{ "messages": [ {role, content (redacted, may be truncated), "truncated": bool} ], "tools": [ {name, description} ], "turn": int }` |
| `token.chunk` | `{ "turn": int, "text": str }` |
| `retrieval.hit` | `{ "store": str, "query": str, "hits": [ {id, score, snippet} ], "durationMs": int }` |
| `tool.call.requested` | `{ "callId": str, "name": str, "arguments": object, "turn": int }` |
| `tool.call.started` | `{ "callId": str, "via": "local"\|"mcp", "server": str? }` |
| `tool.call.finished` | `{ "callId": str, "ok": bool, "result": any (redacted/truncated), "durationMs": int, "error": str? }` |
| `mcp.frame` | `{ "server": str, "direction": "out"\|"in", "kind": "request"\|"response"\|"notification"\|"error", "method": str?, "id": int\|str\|null, "payload": object (redacted), "correlatesTo": str? (callId) }` |
| `usage.reported` | `{ "turn": int, "inputTokens": int, "outputTokens": int, "estimatedCostUsd": number? }` |
| `run.error` | `{ "where": "platform"\|"tool"\|"mcp"\|"store"\|"agent", "message": str }` |
| `run.finished` | `{ "durationMs": int, "turns": int, "toolCalls": int, "inputTokens": int, "outputTokens": int, "estimatedCostUsd": number?, "finalText": str }` |

## Trace file (export)
```json
{ "format": "xray-trace", "version": 1, "createdAt": "ISO-8601", "run": { "id": "...", "prompt": "..." }, "events": [ ...envelope events... ] }
```
Import must validate the version and reject unknown major versions with a clear error.

## Rules
- Redact keys matching (case-insensitive) `api_key`, `apikey`, `authorization`, `token`, `secret`, `password` before storing or publishing; the list is configurable.
- Truncate any single string over 8 KB and set `truncated: true`.
- Correlate tool calls to MCP frames via `callId` -> `correlatesTo`.
