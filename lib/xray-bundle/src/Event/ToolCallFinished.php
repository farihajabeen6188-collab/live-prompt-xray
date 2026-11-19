<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class ToolCallFinished implements TraceEvent
{
    public function __construct(
        public string $callId,
        public bool $ok,
        public mixed $result,
        public int $durationMs,
        public ?string $error = null,
    ) {
    }

    public function type(): string
    {
        return 'tool.call.finished';
    }

    public function data(): array
    {
        return ['callId' => $this->callId, 'ok' => $this->ok, 'result' => $this->result, 'durationMs' => $this->durationMs, 'error' => $this->error];
    }
}
