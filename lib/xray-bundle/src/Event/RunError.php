<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class RunError implements TraceEvent
{
    /**
     * @param 'platform'|'tool'|'mcp'|'store'|'agent' $where
     */
    public function __construct(
        public string $where,
        public string $message,
        public ?string $callId = null,
    ) {
    }

    public function type(): string
    {
        return 'run.error';
    }

    public function data(): array
    {
        return ['where' => $this->where, 'message' => $this->message, 'callId' => $this->callId];
    }
}
