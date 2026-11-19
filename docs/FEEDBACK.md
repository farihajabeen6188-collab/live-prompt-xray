# Feedback on Symfony AI 1.0.0-BETA1 and PHP MCP SDK

Notes collected while building Live Prompt X-Ray: instrumentation hook points, streaming APIs,
MCP transport tapping, and anything that was hard to observe from the outside.

## 2026-11-19 Installation: `composer require symfony/ai-bundle` installs 0.15, not 1.0 beta
- What I tried: `composer require symfony/ai-bundle symfony/ai-agent symfony/ai-platform symfony/ai-store symfony/mcp-bundle`
  in a fresh Symfony 7.4 skeleton with `minimum-stability: beta`.
- What happened: with `prefer-stable: true` (the skeleton default), Composer picks `v0.15.0` (stable) over
  `v1.0.0-BETA1`. Nothing warns that the docs describe the 1.0 API.
- Workaround: require `"symfony/ai-bundle:^1.0@beta"` (and the same for each `symfony/ai-*` and `symfony/mcp-bundle` package).
- Upstream issue: not opened.

## 2026-11-19 FrankenPHP Mercure hub (not Symfony AI, noted for other hackathon teams)
- What I tried: the classic Caddyfile `mercure { publisher_jwt … anonymous }` with FrankenPHP 1.13.5.
- What happened: the hub refuses to start: `publisher_jwt` works only in compatibility mode.
- Workaround: add `protocol_version_compatibility 8`, which matches the HS256 tokens the Symfony Mercure client signs.
