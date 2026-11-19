# 90-second video script

| Time | Visual | Voiceover |
|---|---|---|
| 0:00-0:08 | Black screen, a prompt being typed, answer appears with no explanation | "You ask an agent a question. It answers. But what actually happened in there?" |
| 0:08-0:30 | Same prompt re-run in X-Ray: graph lights up, packets fly to the Model, a Tool, an MCP server, back; tokens stream | "Live Prompt X-Ray shows the agent thinking, in realtime: every model turn, every tool call, every MCP request as it happens." |
| 0:30-0:45 | Click the MCP edge: JSON-RPC frames `tools/call` and result in the inspector | "And these are real MCP frames, not logs: requests, responses, latencies, correlated to the tool call." |
| 0:45-1:00 | Scenario 4: slow bar in the waterfall, red flaky failure, retry turns green | "Slow tool? Failing tool? You see it instantly, and where the time went." |
| 1:00-1:15 | Replay at 2x, then the diff view of two runs highlighting the divergence | "Every run is a trace you can replay, export and diff, so you find out why a tiny prompt change changed everything." |
| 1:15-1:30 | Terminal: `composer require`, 5-line config, repo URL | "It is a Symfony bundle: add it to any Symfony AI app in minutes. Think Symfony Profiler, for agents." |

Record in Demo Mode (deterministic). Use 1440x900, large fonts, no cursor jitter.
