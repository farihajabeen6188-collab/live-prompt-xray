# Live Prompt X-Ray build kit

Contents:
- `PROMPT.md`: master prompt to paste into Claude Code
- `CLAUDE.md`: project rules (copy to the project root)
- `docs/`: architecture + tree, trace schema, UI spec, demo scenarios, video script, submission checklist, feedback log
- `skeleton/`: starter Makefile and .env.example

## How to use
1. `mkdir live-prompt-xray && cd live-prompt-xray && git init`
2. Copy `CLAUDE.md`, `docs/`, and `skeleton/*` into it.
3. Start Claude Code there and paste everything below the line in `PROMPT.md`.
4. Review every phase gate before letting it continue.
5. Follow `docs/SUBMISSION_CHECKLIST.md`. Deadline: 22 Nov 2026, 18:00 CET.

Tip: if time runs short, keep the priority order in PROMPT.md section 7. The live graph, token stream and waterfall matter most.
