<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\RetrieverInterface;
use XRay\Event\RetrievalHit;
use XRay\Event\RunError;
use XRay\Recorder\Recorder;

/**
 * Records retrieval queries with their hits and scores.
 *
 * A retriever is the hook point that knows both the query text and the scored documents;
 * a store only ever sees the vector.
 */
final readonly class TracingRetriever implements RetrieverInterface
{
    public function __construct(
        private RetrieverInterface $inner,
        private Recorder $recorder,
        private string $name,
    ) {
    }

    public function retrieve(string $query, array $options = []): iterable
    {
        $context = $this->recorder->context();
        if (null === $context->current()) {
            return $this->inner->retrieve($query, $options);
        }

        $startedAt = $context->now();
        try {
            $documents = [];
            foreach ($this->inner->retrieve($query, $options) as $document) {
                $documents[] = $document;
            }
        } catch (\Throwable $e) {
            $this->recorder->emit(new RunError('store', $e->getMessage()));
            throw $e;
        }

        $this->recorder->emit(new RetrievalHit(
            $this->name,
            $query,
            array_map(self::hit(...), $documents),
            intdiv(max(0, $context->now() - $startedAt), 1_000_000),
        ));

        return $documents;
    }

    /**
     * @return array{id: string, score: float|null, snippet: string, source: string|null}
     */
    private static function hit(VectorDocumentInterface $document): array
    {
        $metadata = $document->getMetadata();
        $text = (string) ($metadata->getText() ?? '');
        $source = $metadata->getSource();

        return [
            'id' => (string) $document->getId(),
            'score' => $document->getScore(),
            'snippet' => mb_strlen($text) > 280 ? mb_substr($text, 0, 280).'…' : $text,
            'source' => null === $source ? null : (string) $source,
        ];
    }
}
