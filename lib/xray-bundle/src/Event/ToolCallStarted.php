<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class ToolCallStarted implements TraceEvent
{
    public function __construct(
        public string $callId,
        public string $name,
        public string $via,
        public ?string $server = null,
    ) {
    }

    public function type(): string
    {
        return 'tool.call.started';
    }

    public function data(): array
    {
        return ['callId' => $this->callId, 'name' => $this->name, 'via' => $this->via, 'server' => $this->server];
    }
}
