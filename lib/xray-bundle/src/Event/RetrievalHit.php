<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class RetrievalHit implements TraceEvent
{
    /**
     * @param list<array{id: string, score: float|null, snippet: string, source: string|null}> $hits
     */
    public function __construct(
        public string $store,
        public string $query,
        public array $hits,
        public int $durationMs,
    ) {
    }

    public function type(): string
    {
        return 'retrieval.hit';
    }

    public function data(): array
    {
        return ['store' => $this->store, 'query' => $this->query, 'hits' => $this->hits, 'durationMs' => $this->durationMs];
    }
}
