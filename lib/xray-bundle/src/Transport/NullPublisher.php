<?php

declare(strict_types=1);

namespace XRay\Transport;

/**
 * Used with the "sse" transport: viewers read events from the trace store through
 * /_xray/runs/{id}/stream, so nothing is pushed.
 */
final readonly class NullPublisher implements EventPublisher
{
    public function publish(string $runId, array $event): bool
    {
        return true;
    }
}
