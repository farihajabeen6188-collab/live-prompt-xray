<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Demo\Calculator;
use App\Demo\NotesRepository;
use App\Demo\WeatherService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The demo MCP server's tools, served over Streamable HTTP at /mcp by symfony/mcp-bundle.
 * The demo agent reaches them through the MCP client, so every call is real JSON-RPC traffic.
 */
final readonly class DemoTools
{
    public function __construct(
        private WeatherService $weather,
        private NotesRepository $notes,
        private Calculator $calculator,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param string $city a city name, e.g. "Paris"
     * @param string $day  "today", "tomorrow" or a weekday
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'get_weather', description: 'Forecast for a city and day (fictional, deterministic demo data).', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getWeather(string $city, string $day = 'tomorrow'): array
    {
        return $this->weather->forecast($city, $day);
    }

    /**
     * @param string $query words to look for in the user's notes
     *
     * @return list<array{title: string, text: string}>
     */
    #[McpTool(name: 'search_notes', description: 'Searches the user\'s personal notes.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function searchNotes(string $query): array
    {
        return $this->notes->search($query);
    }

    /**
     * @param string $expression arithmetic with + - * / ^ and parentheses, e.g. "240 * 0.18"
     *
     * @return array{expression: string, result: float|int}
     */
    #[McpTool(name: 'calculate', description: 'Evaluates an arithmetic expression safely.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function calculate(string $expression): array
    {
        try {
            return ['expression' => $expression, 'result' => $this->calculator->evaluate($expression)];
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage(), previous: $e);
        }
    }

    /**
     * @param string $topic what to look up
     *
     * @return array{topic: string, summary: string, tookMs: int}
     */
    #[McpTool(name: 'slow_lookup', description: 'Looks a topic up in a slow archive (takes about 2 seconds).', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function slowLookup(string $topic): array
    {
        $startedAt = hrtime(true);
        usleep(2_000_000);

        return [
            'topic' => $topic,
            'summary' => \sprintf('Archive entry for "%s": revenue grew 12%% quarter over quarter, driven by annual plans; churn stayed at 2.1%%.', $topic),
            'tookMs' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ];
    }

    /**
     * @param int $id the service to check
     *
     * @return array{id: int, status: string, attempt: int}
     */
    #[McpTool(name: 'flaky_service', description: 'Checks a service. A cold service fails once (503), a retry within 10 seconds succeeds.')]
    public function flakyService(int $id): array
    {
        // The 2026 MCP revision is stateless (no session across calls), so "first call" means
        // the first call after the service has been idle for 10 seconds.
        $item = $this->cache->getItem(\sprintf('flaky.%d', $id));
        $attempt = ($item->isHit() && \is_int($item->get()) ? $item->get() : 0) + 1;
        $this->cache->save($item->set($attempt)->expiresAfter(10));

        if (1 === $attempt) {
            throw new ToolCallException(\sprintf('Service %d is temporarily unavailable (503). Retry later.', $id));
        }

        return ['id' => $id, 'status' => 'healthy', 'attempt' => $attempt];
    }
}
