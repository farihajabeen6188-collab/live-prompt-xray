# Submission checklist (deadline: 22 Nov 2026, 18:00 CET)

Status as of 2026-11-19. A box is ticked only when the check was actually run.

- [ ] Public GitHub repo, clonable. **Needs you**: the repo is local only (`D:\live-prompt-xray-kit`), nothing pushed.
  Also set your own git name/email first (the repo-local identity is a placeholder).
- [~] Fresh-clone test: `git clone` -> `docker compose up -d` -> live X-Ray run in Demo Mode. **Works**, verified on a
  clean clone with fresh volumes (scenario run in the browser, no failed requests). **Took 303 s, not < 2 min**, on
  this machine: almost all of it is Composer downloading packages into an empty cache.
- [x] Real instrumentation verified by tests: Agent, Toolbox, Platform, Store (retriever), MCP frames
  (`AgentInstrumentationTest`, `TracingRetrieverTest`, `McpCaptureTest` with the real MCP server over STDIO).
- [x] Live graph, token stream, waterfall working on all 5 scenarios (checked in the browser at 1440x900; layout
  also checked at 390px).
- [x] MCP inspector shows correlated frames (UI + `McpCaptureTest`).
- [x] Replay, export/import, diff working (replay and diff in the browser; export/import over HTTP in
  `TraceApiTest`; drag-and-drop import not clicked through in a browser).
- [x] Bundle installs into a fresh app using only its README (fresh `symfony/skeleton` in a temp dir: run recorded and
  streamed, console and all modules 200; checked over HTTP, not in a browser).
- [x] Recorder failure never breaks an agent run (`RecorderTest`, `AgentInstrumentationTest::testTheAgentRunsUnchangedWhenTheRecorderCannotPublish`).
- [x] Secrets redacted by default (`RedactorTest`, `RecorderTest::testSecretsAreRedactedBeforeStorageAndTransport`).
- [x] `make stan cs test` green (`./dev.ps1 stan cs test`: PHPStan level 8 no errors, CS clean, 59 tests).
- [~] `docs/FEEDBACK.md` has real notes on Symfony AI + PHP MCP SDK. Upstream issues **not opened** (needs your GitHub account).
- [x] README: what/why, quickstart, demo vs live model, bundle install, Mermaid architecture, security notes.
- [ ] A real model end to end: connecting any OpenAI-compatible API from the console works up to the connection
  test (verified against the local Ollama: reached in 53 ms, 0 models). **No real model has answered yet**: no API key
  here and no Ollama model pulled. Connect one before mentioning it in the video.
- [ ] 90-second video recorded, uploaded, link tested. **Needs you** (script: `docs/VIDEO_SCRIPT.md`).
- [x] No secrets in git history (gitleaks: only the Symfony recipe's dev placeholder `APP_SECRET`, documented in
  `.gitleaksignore`).
- [ ] Submit EARLY on symfony.com/hackathon (edits allowed until the deadline). **Needs you.**
