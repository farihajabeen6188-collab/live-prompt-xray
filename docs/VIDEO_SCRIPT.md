# 90-second video script

Record in Demo Mode (deterministic) at 1440x900, browser zoom 110%, slow cursor movements. Start from a
fresh `docker compose up -d` with the console open at http://localhost:8090 and the "Try a scenario" panel visible.
Wait 11 seconds between two takes of scene 4 so the flaky service is "cold" again.

| Time | Visual | Voiceover |
|---|---|---|
| 0:00-0:08 | Only the token stream panel (cropped): an answer appears with no explanation | "You ask an agent a question. It answers. But what actually happened in there?" |
| 0:08-0:30 | Click **Umbrella in Paris**. Full console: packets fly User → Agent → Model, then violet packets Agent → MCP server `demo` → `get_weather`; tokens stream; waterfall bars grow; meters count | "Live Prompt X-Ray shows the agent thinking, in realtime: every model turn, every tool call, every MCP request, as it happens." |
| 0:30-0:45 | Click the `get_weather` node, then **Show its MCP frames**: `→ tools/call #3` and `← tools/call #3 · 172 ms`; expand the payload | "And these are real JSON-RPC frames from the MCP client, not logs: requests, responses, latencies, correlated to the tool call." |
| 0:45-1:00 | Scenario **Slow tool, flaky service**: the 2-second `slow_lookup` bar, the red `flaky_service` round trip and shake, the retry, "healthy (attempt 2)", the Errors meter at 1 | "Slow tool? Failing tool? You see it instantly, and where the time went." |
| 1:00-1:15 | **Runs** → Replay at 2x with the scrub bar, then tick the two umbrella runs → **Compare**: the steps diverge at step 1, the deltas | "Every run is a trace you can replay, export and diff, so you find out why a tiny prompt change changed everything." |
| 1:15-1:30 | Editor: `composer require --dev xray/xray-bundle` and the 3-line route file, then the console following a run from another app; end card with the repository URL | "It is a Symfony bundle: add it to any Symfony AI app in minutes. Think Symfony Profiler, for agents." |
