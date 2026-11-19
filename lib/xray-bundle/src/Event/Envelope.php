<?php

declare(strict_types=1);

namespace XRay\Event;

/**
 * The wire format of every trace event (schema v1).
 */
final readonly class Envelope
{
    public const int VERSION = 1;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $runId,
        public int $seq,
        public int $ts,
        public string $type,
        public array $data,
    ) {
    }

    /**
     * @return array{v: int, runId: string, seq: int, ts: int, type: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['v' => self::VERSION, 'runId' => $this->runId, 'seq' => $this->seq, 'ts' => $this->ts, 'type' => $this->type, 'data' => $this->data];
    }

    /**
     * @param array<mixed> $event
     */
    public static function fromArray(array $event): self
    {
        if (self::VERSION !== ($event['v'] ?? null)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported trace event version "%s".', get_debug_type($event['v'] ?? null)));
        }
        if (!\is_string($event['runId'] ?? null) || !\is_int($event['seq'] ?? null) || !\is_int($event['ts'] ?? null) || !\is_string($event['type'] ?? null) || !\is_array($event['data'] ?? null)) {
            throw new \InvalidArgumentException('A trace event needs "runId", "seq", "ts", "type" and "data".');
        }

        /** @var array<string, mixed> $data */
        $data = $event['data'];

        return new self($event['runId'], $event['seq'], $event['ts'], $event['type'], $data);
    }
}
