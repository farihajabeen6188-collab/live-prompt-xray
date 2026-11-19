<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class UsageReported implements TraceEvent
{
    /**
     * @param bool $estimated true when the provider reported no usage and X-Ray counted tokens itself
     */
    public function __construct(
        public int $turn,
        public int $inputTokens,
        public int $outputTokens,
        public ?float $estimatedCostUsd,
        public int $durationMs,
        public bool $estimated,
    ) {
    }

    public function type(): string
    {
        return 'usage.reported';
    }

    public function data(): array
    {
        return [
            'turn' => $this->turn,
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'estimatedCostUsd' => $this->estimatedCostUsd,
            'durationMs' => $this->durationMs,
            'estimated' => $this->estimated,
        ];
    }
}
