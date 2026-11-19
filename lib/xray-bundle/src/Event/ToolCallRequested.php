<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class ToolCallRequested implements TraceEvent
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments,
        public int $turn,
    ) {
    }

    public function type(): string
    {
        return 'tool.call.requested';
    }

    public function data(): array
    {
        return ['callId' => $this->callId, 'name' => $this->name, 'arguments' => $this->arguments, 'turn' => $this->turn];
    }
}
