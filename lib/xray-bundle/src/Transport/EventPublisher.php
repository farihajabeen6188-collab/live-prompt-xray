<?php

declare(strict_types=1);

namespace XRay\Transport;

/**
 * Delivers trace events to live viewers. Implementations must never throw.
 */
interface EventPublisher
{
    /**
     * @param array<string, mixed> $event a trace envelope (see docs/TRACE_SCHEMA.md)
     */
    public function publish(string $runId, array $event): bool;
}
