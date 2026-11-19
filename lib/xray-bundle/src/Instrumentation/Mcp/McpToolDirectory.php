<?php

declare(strict_types=1);

namespace XRay\Instrumentation\Mcp;

/**
 * Which MCP server serves which tool, learned from the tools/list responses the frame tap sees.
 * Shared between connections, so a reused connection that skips tools/list is still recognised.
 */
final class McpToolDirectory
{
    /** @var array<string, string> remote tool name => server */
    private array $tools = [];

    public function learn(string $tool, string $server): void
    {
        $this->tools[$tool] = $server;
    }

    /**
     * MCP toolboxes may prefix remote names, e.g. "demo_get_weather" for "get_weather".
     */
    public function serverOf(string $toolName): ?string
    {
        foreach ($this->tools as $remoteName => $server) {
            if ($toolName === $remoteName || str_ends_with($toolName, '_'.$remoteName)) {
                return $server;
            }
        }

        return null;
    }
}
