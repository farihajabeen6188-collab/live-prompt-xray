<?php

declare(strict_types=1);

namespace XRay\Tests\Support;

use XRay\Event\Envelope;
use XRay\Storage\RunRecord;
use XRay\Storage\TraceStore;

final class FailingTraceStore implements TraceStore
{
    public function beginRun(RunRecord $run): void
    {
        throw new \RuntimeException('disk full');
    }

    public function append(Envelope $event): void
    {
        throw new \RuntimeException('disk full');
    }

    public function finishRun(string $runId, string $status, array $summary): void
    {
        throw new \RuntimeException('disk full');
    }

    public function run(string $runId): ?RunRecord
    {
        throw new \RuntimeException('disk full');
    }

    public function runs(int $limit = 50): array
    {
        throw new \RuntimeException('disk full');
    }

    public function events(string $runId, int $afterSeq = 0): array
    {
        throw new \RuntimeException('disk full');
    }

    public function save(RunRecord $run, array $events): void
    {
        throw new \RuntimeException('disk full');
    }
}
