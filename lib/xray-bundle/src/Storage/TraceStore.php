<?php

declare(strict_types=1);

namespace XRay\Storage;

use XRay\Event\Envelope;

interface TraceStore
{
    public function beginRun(RunRecord $run): void;

    public function append(Envelope $event): void;

    /**
     * @param 'finished'|'failed'  $status
     * @param array<string, mixed> $summary
     */
    public function finishRun(string $runId, string $status, array $summary): void;

    public function run(string $runId): ?RunRecord;

    /**
     * @return list<RunRecord> newest first
     */
    public function runs(int $limit = 50): array;

    /**
     * @return list<Envelope> ordered by seq
     */
    public function events(string $runId, int $afterSeq = 0): array;

    /**
     * Stores a complete run (used by trace import). Replaces a run with the same id.
     *
     * @param list<Envelope> $events
     */
    public function save(RunRecord $run, array $events): void;
}
