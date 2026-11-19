# Hackathon submission text

Copy into the Symfony AI & MCP Hackathon form (symfony.com/hackathon). Fill in the two links you only get later.

**Project name:** Live Prompt X-Ray

**Repository:** https://github.com/farihajabeen6188-collab/live-prompt-xray

**Live demo:** <your Render URL, e.g. https://live-prompt-xray.onrender.com> (Demo Mode; the first visit may take a minute to wake up)

**Video:** <link to the 90-second video>

**One-liner:** A realtime X-Ray of a Symfony AI agent: it reads your message as you type, then shows the agent think
(model turns, streamed tokens, tool calls, retrieval, real MCP JSON-RPC frames) live, and records every run to
replay, export and diff.

**Description:**
Agents are black boxes. Live Prompt X-Ray is a Symfony bundle that makes them visible, like the Symfony Profiler
does for HTTP requests.

- *Pre-flight X-Ray while you type:* it dry-runs the agent's own input processors on the draft (system prompt,
  RAG), without calling the model, and shows the exact context the model will receive, what each processor added,
  the documents retrieval finds and which get injected, and the tool calls the model will make, with a ghost graph
  of the planned path.
- *Live brain view while it runs:* a graph where model, tools, the docs store and MCP servers light up and packets
  travel; streamed tokens; a timeline of every step; the real JSON-RPC frames of every MCP call, correlated with
  the tool call; latency, token and cost meters. It is a chat: follow-ups carry the conversation.
- *Every run is a trace:* replay at any speed with a scrub bar, export/import as JSON, diff two runs to see where
  they diverge.
- *Real instrumentation:* the Agent, Platform, Toolbox, Retriever and the MCP client transport are decorated by a
  compiler pass (no patching); failures in X-Ray never break a run; secrets are redacted before anything leaves
  the process.
- *Works with zero keys:* a rules-based demo model drives real tools, a real MCP server (mcp-bundle) and real RAG;
  or connect any OpenAI-compatible API (OpenAI, OpenRouter, Groq, Gemini, Mistral, Ollama…) from the console.

**Built with:** Symfony 7.4, symfony/ai-* 1.0.0-BETA1 (Agent, Platform, Store, generic bridge, MCP tool),
symfony/mcp-bundle 1.0.0-BETA1 and mcp/sdk 0.9, FrankenPHP with its Mercure hub, AssetMapper + Stimulus,
PHPUnit (59 tests), PHPStan level 8.

**Beta feedback:** see docs/FEEDBACK.md (installation pitfalls, MCP 2026-07-28 handshake and statelessness, the
Retriever's hybrid fallback, `MessageBag::getUserMessage()` returning the first message, hook points that worked well).
