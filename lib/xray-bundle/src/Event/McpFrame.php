<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class McpFrame implements TraceEvent
{
    /**
     * @param 'out'|'in'                                  $direction
     * @param 'request'|'response'|'notification'|'error' $kind
     * @param array<string, mixed>                        $payload
     */
    public function __construct(
        public string $server,
        public string $direction,
        public string $kind,
        public ?string $method,
        public int|string|null $id,
        public array $payload,
        public ?string $correlatesTo = null,
        public ?int $latencyMs = null,
    ) {
    }

    public function type(): string
    {
        return 'mcp.frame';
    }

    public function data(): array
    {
        return [
            'server' => $this->server,
            'direction' => $this->direction,
            'kind' => $this->kind,
            'method' => $this->method,
            'id' => $this->id,
            'payload' => $this->payload,
            'correlatesTo' => $this->correlatesTo,
            'latencyMs' => $this->latencyMs,
        ];
    }
}
