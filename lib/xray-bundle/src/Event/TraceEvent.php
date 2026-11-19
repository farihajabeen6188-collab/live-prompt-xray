<?php

declare(strict_types=1);

namespace XRay\Event;

/**
 * One typed fact about an agent run. The Recorder wraps it in an {@see Envelope}
 * (run id, sequence number, timestamp) before it is redacted, stored and published.
 */
interface TraceEvent
{
    /**
     * The wire type, e.g. "tool.call.finished" (see docs/TRACE_SCHEMA.md).
     */
    public function type(): string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;
}
