<?php

declare(strict_types=1);

namespace XRay\Diff;

use XRay\Event\Envelope;

/**
 * Compares two runs: prompt text, the sequence of steps (retrievals, tool calls, answers),
 * token and latency deltas, and the first step where the runs diverge.
 */
final class TraceDiffer
{
    /**
     * @param list<Envelope> $a
     * @param list<Envelope> $b
     *
     * @return array{
     *     a: array<string, mixed>,
     *     b: array<string, mixed>,
     *     prompt: list<array{op: string, text: string}>,
     *     answer: list<array{op: string, text: string}>,
     *     steps: list<array{op: string, a: ?array{kind: string, label: string, turn: int}, b: ?array{kind: string, label: string, turn: int}}>,
     *     deltas: array<string, int|float|null>,
     *     divergence: ?array{index: int, a: ?array{kind: string, label: string, turn: int}, b: ?array{kind: string, label: string, turn: int}}
     * }
     */
    public function diff(array $a, array $b): array
    {
        $summaryA = self::summary($a);
        $summaryB = self::summary($b);
        $stepsA = self::steps($a);
        $stepsB = self::steps($b);

        $divergence = null;
        $length = max(\count($stepsA), \count($stepsB));
        for ($i = 0; $i < $length; ++$i) {
            if (($stepsA[$i]['label'] ?? null) !== ($stepsB[$i]['label'] ?? null)) {
                $divergence = ['index' => $i, 'a' => $stepsA[$i] ?? null, 'b' => $stepsB[$i] ?? null];
                break;
            }
        }

        $deltas = [];
        foreach (['durationMs', 'turns', 'toolCalls', 'inputTokens', 'outputTokens', 'estimatedCostUsd'] as $key) {
            $deltas[$key] = \is_int($summaryA[$key]) && \is_int($summaryB[$key])
                ? $summaryB[$key] - $summaryA[$key]
                : (is_numeric($summaryA[$key]) && is_numeric($summaryB[$key]) ? round((float) $summaryB[$key] - (float) $summaryA[$key], 6) : null);
        }

        $steps = [];
        foreach (self::lcs(array_column($stepsA, 'label'), array_column($stepsB, 'label')) as [$op, $i, $j]) {
            $steps[] = ['op' => $op, 'a' => null === $i ? null : $stepsA[$i], 'b' => null === $j ? null : $stepsB[$j]];
        }

        return [
            'a' => $summaryA,
            'b' => $summaryB,
            'prompt' => self::textDiff((string) $summaryA['prompt'], (string) $summaryB['prompt']),
            'answer' => self::textDiff((string) $summaryA['finalText'], (string) $summaryB['finalText']),
            'steps' => $steps,
            'deltas' => $deltas,
            'divergence' => $divergence,
        ];
    }

    /**
     * @param list<Envelope> $events
     *
     * @return array{runId: string, prompt: string, finalText: string, durationMs: ?int, turns: ?int, toolCalls: ?int, inputTokens: ?int, outputTokens: ?int, estimatedCostUsd: float|int|null}
     */
    private static function summary(array $events): array
    {
        $summary = ['runId' => $events[0]->runId ?? '', 'prompt' => '', 'finalText' => '', 'durationMs' => null, 'turns' => null, 'toolCalls' => null, 'inputTokens' => null, 'outputTokens' => null, 'estimatedCostUsd' => null];
        foreach ($events as $event) {
            if ('run.started' === $event->type) {
                $summary['prompt'] = (string) ($event->data['prompt'] ?? '');
            }
            if ('run.finished' === $event->type) {
                foreach (['durationMs', 'turns', 'toolCalls', 'inputTokens', 'outputTokens'] as $key) {
                    $summary[$key] = \is_int($event->data[$key] ?? null) ? $event->data[$key] : null;
                }
                $cost = $event->data['estimatedCostUsd'] ?? null;
                $summary['estimatedCostUsd'] = \is_float($cost) || \is_int($cost) ? $cost : null;
                $summary['finalText'] = (string) ($event->data['finalText'] ?? '');
            }
        }

        return $summary;
    }

    /**
     * The semantic steps of a run, comparable across runs (ids and timings left out).
     *
     * @param list<Envelope> $events
     *
     * @return list<array{kind: string, label: string, turn: int}>
     */
    private static function steps(array $events): array
    {
        $steps = [];
        $answers = [];
        $turn = 0;
        foreach ($events as $event) {
            $data = $event->data;
            switch ($event->type) {
                case 'prompt.sent':
                    $turn = (int) ($data['turn'] ?? $turn + 1);
                    break;
                case 'retrieval.hit':
                    $ids = array_map(static fn (mixed $hit): string => \is_array($hit) ? (string) ($hit['id'] ?? '') : '', \is_array($data['hits'] ?? null) ? $data['hits'] : []);
                    $steps[] = ['kind' => 'retrieval', 'label' => \sprintf('retrieve "%s" → [%s]', (string) ($data['query'] ?? ''), implode(', ', $ids)), 'turn' => $turn];
                    break;
                case 'tool.call.requested':
                    $steps[] = ['kind' => 'tool', 'label' => \sprintf('%s(%s)', (string) ($data['name'] ?? ''), json_encode($data['arguments'] ?? [], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)), 'turn' => $turn];
                    break;
                case 'tool.call.finished':
                    if (false === ($data['ok'] ?? true)) {
                        $steps[] = ['kind' => 'error', 'label' => 'tool failed: '.(string) ($data['error'] ?? ''), 'turn' => $turn];
                    }
                    break;
                case 'token.chunk':
                    $answers[$turn] = ($answers[$turn] ?? '').(string) ($data['text'] ?? '');
                    break;
                case 'usage.reported':
                    if (isset($answers[$turn]) && '' !== trim($answers[$turn])) {
                        $steps[] = ['kind' => 'answer', 'label' => 'answer: '.trim($answers[$turn]), 'turn' => $turn];
                        unset($answers[$turn]);
                    }
                    break;
            }
        }

        return $steps;
    }

    /**
     * @return list<array{op: string, text: string}>
     */
    private static function textDiff(string $a, string $b): array
    {
        $wordsA = self::words($a);
        $wordsB = self::words($b);
        $ops = [];
        foreach (self::lcs($wordsA, $wordsB) as [$op, $i, $j]) {
            $text = 'add' === $op ? $wordsB[(int) $j] : $wordsA[(int) $i];
            $last = array_key_last($ops);
            if (null !== $last && $ops[$last]['op'] === $op) {
                $ops[$last]['text'] .= $text;
            } else {
                $ops[] = ['op' => $op, 'text' => $text];
            }
        }

        return $ops;
    }

    /**
     * @return list<string> words with their trailing whitespace
     */
    private static function words(string $text): array
    {
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Longest-common-subsequence alignment.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: 'same'|'del'|'add', 1: ?int, 2: ?int}>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $table[$i][$j] = $a[$i] === $b[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['same', $i++, $j++];
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['del', $i++, null];
            } else {
                $ops[] = ['add', null, $j++];
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $i++, null];
        }
        while ($j < $m) {
            $ops[] = ['add', null, $j++];
        }

        return $ops;
    }
}
