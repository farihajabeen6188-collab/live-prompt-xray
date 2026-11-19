<?php

declare(strict_types=1);

namespace XRay\Recorder;

/**
 * Mutable state of the run being recorded. Lives in {@see RunContext} for exactly one agent call.
 *
 * @internal
 */
final class Run
{
    private int $seq = 0;
    public int $turn = 0;
    public int $toolCalls = 0;
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public ?float $costUsd = null;
    public int $errors = 0;
    public string $finalText = '';

    /** @var array<string, array{name: string, arguments: array<string, mixed>}> callIds waiting for their MCP tools/call */
    public array $awaitingMcp = [];

    /** @var list<array{v: int, runId: string, seq: int, ts: int, type: string, data: array<string, mixed>}> events of a preview, kept in memory only */
    public array $captured = [];

    /**
     * @param bool $preview a dry run while the user types: events are captured, never stored or published
     */
    public function __construct(
        public readonly string $id,
        public readonly string $model,
        private readonly int $startedAtNs,
        public readonly bool $preview = false,
    ) {
    }

    public function nextSeq(): int
    {
        return ++$this->seq;
    }

    public function elapsedMs(int $nowNs): int
    {
        return intdiv(max(0, $nowNs - $this->startedAtNs), 1_000_000);
    }

    public function addCost(?float $cost): void
    {
        if (null !== $cost) {
            $this->costUsd = ($this->costUsd ?? 0.0) + $cost;
        }
    }
}
