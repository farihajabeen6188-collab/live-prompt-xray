<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class RunFinished implements TraceEvent
{
    public function __construct(
        public int $durationMs,
        public int $turns,
        public int $toolCalls,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCostUsd,
        public string $finalText,
        public bool $ok,
    ) {
    }

    public function type(): string
    {
        return 'run.finished';
    }

    public function data(): array
    {
        return [
            'durationMs' => $this->durationMs,
            'turns' => $this->turns,
            'toolCalls' => $this->toolCalls,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'estimatedCostUsd' => $this->estimatedCostUsd,
            'finalText' => $this->finalText,
            'ok' => $this->ok,
        ];
    }
}
