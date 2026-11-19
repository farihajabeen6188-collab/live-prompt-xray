# Demo scenarios (all work in Demo Mode with the scripted model; tools, MCP and RAG are real)

## Demo MCP server tools (via mcp-bundle, `#[McpTool]`)
- `get_weather(city)` returns fake but deterministic data
- `search_notes(query)` searches a small notes list
- `calculate(expression)` safe arithmetic
- `slow_lookup(topic)` deliberately sleeps ~2s (shows up as a long waterfall bar)
- `flaky_service(id)` fails on first call, succeeds on retry (shows error edge and recovery)

RAG corpus: ~10 short markdown docs about a fictional product ("Nimbus Cloud") in `app/corpus/`, indexed in a local Store.

## Scenarios
1. **"Do I need an umbrella in Paris tomorrow?"**
   Model turn 1 -> tool call `get_weather` via MCP -> turn 2 final answer. Shows: graph lights up, MCP frames (`initialize`, `tools/list`, `tools/call`), token stream.
2. **"What is our refund policy for annual plans?"**
   Retrieval from Store (hits with scores) -> injected context -> answer citing the snippet. Shows: RAG node, context inspector highlighting injected snippet.
3. **"Plan my Friday: weather in Berlin, my notes about the offsite, and 18% tip on 240 EUR"**
   Parallel tool calls: `get_weather`, `search_notes`, `calculate`. Shows: parallel edges, waterfall overlap.
4. **"Look up the Q4 report topic and check service 42"**
   `slow_lookup` (2s bar) and `flaky_service` (red failure, retry, green). Shows: error handling, latency, retry.
5. **"Summarize everything you know about Nimbus pricing"**
   Multi-turn: retrieval -> tool call -> retrieval -> final. Shows: multi-turn context growth, token meter climbing.

## Diff demo
Run scenario 1 twice with a slightly different prompt ("Do I need an umbrella?" vs "Do I need an umbrella in Paris tomorrow evening?"). The scripted model branches differently; the diff view highlights the divergence.

## Live mode
If `DOJO_PLATFORM`-style env (see `.env.example`) selects Ollama/hosted, the same scenarios run against the real model; traces will vary, which is fine.
