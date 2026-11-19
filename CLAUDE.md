# CLAUDE.md: project rules for Live Prompt X-Ray

Project: realtime visual debugger for Symfony AI agents + MCP, shipped as `lib/xray-bundle` and a demo app in `app/`. Hackathon deadline: 22 Nov 2026, 18:00 CET.

## Source of truth
`docs/ARCHITECTURE.md`, `docs/TRACE_SCHEMA.md`, `docs/UI_SPEC.md`, `docs/DEMO_SCENARIOS.md`.
Symfony AI is 1.0 beta; PHP MCP SDK ~0.9. ALWAYS read vendor code before using an API. Never guess class names, attributes or config keys. Log surprises in `docs/FEEDBACK.md`.

## Conventions
- PHP 8.4, `declare(strict_types=1)`, final by default, readonly value objects, constructor promotion.
- Symfony 7.4, attributes, autowiring, decoration via `#[AsDecorator]`/service decoration.
- Instrumentation by decoration/listeners only. Never edit vendor.
- Recorder failures must never break an agent run. Wrap and swallow with a logged warning.
- Redact secrets by default. Redaction runs BEFORE events leave the process.
- Frontend: Twig + Stimulus + Turbo, AssetMapper, SVG/CSS animation. No CDNs. Vendor JS locally. Respect `prefers-reduced-motion`.
- Events have monotonically increasing `seq` per run; clients re-order and de-dupe.
- Tests: PHPUnit; use `ScriptedPlatform`, never the network. Test event sequences exactly.

## Commands
`make up`, `make down`, `make test`, `make stan`, `make cs`

## Don'ts
No secrets in git. No TODOs in committed code. No dead code. No scope creep before the priority list in PROMPT.md section 7 is satisfied in order.
