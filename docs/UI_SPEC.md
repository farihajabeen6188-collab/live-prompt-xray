# UI specification

Visual language: dark, crisp, "mission control". Monospace for data, a clean sans for labels. One accent color for activity, one for errors, one for MCP traffic. No external fonts or CDNs. Respect `prefers-reduced-motion` (replace motion with instant state changes).

## Layout (desktop, 1440x900 target; tablet usable)
```
┌──────────────────────────────────────────────────────────────────────────┐
│ Prompt box  [Run] [Scenario ▾] [Mode: Demo/Live] [Replay ⏮ ▶ ⏭ 0.5x-4x]    │
├───────────────────────────────────┬──────────────────────────────────────┤
│                                   │  Token stream (answer, streaming)    │
│   LIVE GRAPH (hero, ~55% width)   ├──────────────────────────────────────┤
│                                   │  Inspector tabs:                     │
│                                   │  Prompt/Context | Tool call | MCP    │
├───────────────────────────────────┴──────────────────────────────────────┤
│ Waterfall timeline (per step bars, durations)        │ Meters            │
└──────────────────────────────────────────────────────────────────────────┘
```

## 1. Live graph (the hero)
- Nodes: User, Agent, Model, one per Tool, one per MCP server, Store. Layout is stable and readable (fixed or lightly force-directed, no jitter).
- Nodes appear when first used, glow while active, dim after.
- Edges animate "packets" traveling in the direction of data: prompt to model, tool request to tool, MCP request to server, result back, retrieval hits from store.
- MCP edges use the MCP accent color; failed calls turn the edge and node red with a shake (not with reduced motion).
- Click a node or edge to focus the inspector on that call.
- Hover shows a small tooltip: name, last duration, call count.

## 2. Token stream
Streams the model's answer as it arrives. Marks turn boundaries. Tool-call turns display "calling toolName(args)" inline in the stream.

## 3. Inspector tabs
- **Prompt/Context**: exact messages and tool schemas sent each turn (redacted/truncated markers visible), turn selector, token estimate per message, RAG snippets highlighted where they were injected.
- **Tool call**: arguments (pretty JSON), result, duration, ok/error, `via local|mcp`, link to its MCP frames.
- **MCP**: chronological JSON-RPC frames (`->` out / `<-` in), method, id, latency between request and response, collapsible payloads, filter by server/method. Copy-as-JSON button.

## 4. Waterfall
One horizontal bar per step: model turn, tool call, MCP round trip, retrieval. Bars sized by duration, nested where relevant. Click to focus. Live-updating while running.

## 5. Meters
Total latency, time to first token, input/output tokens, tool calls count, estimated cost, errors count. Animated numeric transitions.

## 6. Replay, export, diff
- Replay any stored run at 0.5x/1x/2x/4x, with scrub bar; replay drives the exact same UI path as live.
- Export trace JSON; import by drag and drop.
- Diff view: pick two runs; show side by side what changed (prompt text diff, tool-call sequence diff, token and latency deltas), highlight divergence point.

## 7. States
Empty state with an inviting "Try a scenario" panel; loading states; error banner when the stream drops with auto-retry; clear "Demo mode: scripted model" badge; keyboard accessible controls; sufficient contrast.

## Performance
60fps animation on a mid laptop with 500 events; cap DOM nodes (virtualize long lists); batch UI updates with requestAnimationFrame.
