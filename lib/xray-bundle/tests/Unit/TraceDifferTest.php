<?php

declare(strict_types=1);

namespace XRay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XRay\Diff\TraceDiffer;
use XRay\Event\Envelope;

final class TraceDifferTest extends TestCase
{
    public function testItFindsWhereTwoRunsDivergeAndWhatChanged(): void
    {
        $a = self::trace('run_aaaaaaaa', 'Do I need an umbrella?', [
            ['tool.call.requested', ['callId' => 'c1', 'name' => 'search_notes', 'arguments' => ['query' => 'home city'], 'turn' => 1]],
            ['tool.call.requested', ['callId' => 'c2', 'name' => 'get_weather', 'arguments' => ['city' => 'Paris'], 'turn' => 2]],
        ], 'No umbrella needed.', ['durationMs' => 3000, 'turns' => 3, 'toolCalls' => 2, 'inputTokens' => 900, 'outputTokens' => 40]);
        $b = self::trace('run_bbbbbbbb', 'Do I need an umbrella in Paris tomorrow?', [
            ['tool.call.requested', ['callId' => 'x1', 'name' => 'get_weather', 'arguments' => ['city' => 'Paris'], 'turn' => 1]],
        ], 'You can leave the umbrella at home.', ['durationMs' => 2000, 'turns' => 2, 'toolCalls' => 1, 'inputTokens' => 600, 'outputTokens' => 30]);

        $diff = new TraceDiffer()->diff($a, $b);

        self::assertSame(['op' => 'same', 'text' => 'Do I need an '], $diff['prompt'][0]);
        self::assertSame(['del', 'add'], array_column(\array_slice($diff['prompt'], 1), 'op'));
        self::assertSame('umbrella in Paris tomorrow?', $diff['prompt'][2]['text']);

        self::assertSame(0, $diff['divergence']['index'] ?? null);
        self::assertSame('search_notes({"query":"home city"})', $diff['divergence']['a']['label'] ?? null);
        self::assertSame('get_weather({"city":"Paris"})', $diff['divergence']['b']['label'] ?? null);

        self::assertSame(['del', 'same', 'del', 'add'], array_column($diff['steps'], 'op'), 'search_notes was dropped, get_weather is shared, the answers differ.');
        self::assertSame(['durationMs' => -1000, 'turns' => -1, 'toolCalls' => -1, 'inputTokens' => -300, 'outputTokens' => -10, 'estimatedCostUsd' => null], $diff['deltas']);
    }

    public function testIdenticalRunsDoNotDiverge(): void
    {
        $steps = [['tool.call.requested', ['callId' => 'c1', 'name' => 'calculate', 'arguments' => ['expression' => '1+1'], 'turn' => 1]]];
        $a = self::trace('run_aaaaaaaa', 'Add', $steps, '2', ['durationMs' => 10, 'turns' => 2, 'toolCalls' => 1, 'inputTokens' => 5, 'outputTokens' => 1, 'estimatedCostUsd' => 0.001]);
        $b = self::trace('run_bbbbbbbb', 'Add', $steps, '2', ['durationMs' => 12, 'turns' => 2, 'toolCalls' => 1, 'inputTokens' => 5, 'outputTokens' => 1, 'estimatedCostUsd' => 0.0015]);

        $diff = new TraceDiffer()->diff($a, $b);

        self::assertNull($diff['divergence']);
        self::assertSame(['same', 'same'], array_column($diff['steps'], 'op'));
        self::assertSame(2, $diff['deltas']['durationMs']);
        self::assertSame(0.0005, $diff['deltas']['estimatedCostUsd']);
        self::assertSame([['op' => 'same', 'text' => '2']], $diff['answer']);
    }

    public function testFailedToolCallsAndRetrievalsAreSteps(): void
    {
        $a = self::trace('run_aaaaaaaa', 'Check', [
            ['retrieval.hit', ['store' => 'docs', 'query' => 'refunds', 'hits' => [['id' => '03-refunds', 'score' => 0.5, 'snippet' => '…']], 'durationMs' => 3]],
            ['tool.call.requested', ['callId' => 'c1', 'name' => 'flaky', 'arguments' => [], 'turn' => 1]],
            ['tool.call.finished', ['callId' => 'c1', 'ok' => false, 'result' => null, 'durationMs' => 5, 'error' => 'HTTP 503']],
        ], 'Sorry.', ['durationMs' => 50, 'turns' => 2, 'toolCalls' => 1, 'inputTokens' => 5, 'outputTokens' => 1]);

        $diff = new TraceDiffer()->diff($a, $a);

        self::assertSame(['retrieve "refunds" → [03-refunds]', 'flaky([])', 'tool failed: HTTP 503', 'answer: Sorry.'], array_map(static fn (array $s): string => $s['a']['label'] ?? '', $diff['steps']));
    }

    /**
     * @param list<array{string, array<string, mixed>}> $middle
     * @param array<string, mixed>                      $summary
     *
     * @return list<Envelope>
     */
    private static function trace(string $runId, string $prompt, array $middle, string $answer, array $summary): array
    {
        $seq = 0;
        $events = [new Envelope($runId, ++$seq, 0, 'run.started', ['prompt' => $prompt])];
        $turn = 0;
        foreach ($middle as [$type, $data]) {
            if ('tool.call.requested' === $type && $data['turn'] !== $turn) {
                $turn = $data['turn'];
                $events[] = new Envelope($runId, ++$seq, $seq, 'prompt.sent', ['turn' => $turn, 'messages' => [], 'tools' => []]);
            }
            $events[] = new Envelope($runId, ++$seq, $seq, $type, $data);
        }
        ++$turn;
        $events[] = new Envelope($runId, ++$seq, $seq, 'prompt.sent', ['turn' => $turn, 'messages' => [], 'tools' => []]);
        $events[] = new Envelope($runId, ++$seq, $seq, 'token.chunk', ['turn' => $turn, 'text' => $answer]);
        $events[] = new Envelope($runId, ++$seq, $seq, 'usage.reported', ['turn' => $turn]);
        $events[] = new Envelope($runId, ++$seq, $seq, 'run.finished', [...$summary, 'finalText' => $answer, 'ok' => true]);

        return $events;
    }
}
