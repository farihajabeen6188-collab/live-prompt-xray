# Submission checklist (deadline: 22 Nov 2026, 18:00 CET)

- [ ] Public GitHub repo, clonable
- [ ] Fresh-clone test: `git clone` -> `docker compose up` -> live X-Ray run in Demo Mode (< 2 min)
- [ ] Real instrumentation verified by tests: Agent, Toolbox, Platform, Store, MCP frames
- [ ] Live graph, token stream, waterfall working on all 5 scenarios
- [ ] MCP inspector shows correlated frames
- [ ] Replay, export/import, diff working
- [ ] Bundle installs into a fresh app using only its README
- [ ] Recorder failure never breaks an agent run (test exists)
- [ ] Secrets redacted by default (test exists)
- [ ] `make stan cs test` green
- [ ] `docs/FEEDBACK.md` has real notes on Symfony AI + PHP MCP SDK (open upstream issues for real bugs)
- [ ] README: what/why, quickstart, demo vs live model, bundle install, Mermaid architecture, security notes
- [ ] 90-second video recorded, uploaded, link tested
- [ ] No secrets in git history
- [ ] Submit EARLY on symfony.com/hackathon (edits allowed until the deadline)
