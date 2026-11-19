<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class TokenChunk implements TraceEvent
{
    public function __construct(
        public int $turn,
        public string $text,
    ) {
    }

    public function type(): string
    {
        return 'token.chunk';
    }

    public function data(): array
    {
        return ['turn' => $this->turn, 'text' => $this->text];
    }
}
