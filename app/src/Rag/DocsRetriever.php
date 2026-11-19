<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\AI\Store\StoreInterface;

/**
 * Semantic retrieval over the docs store with a plain VectorQuery.
 *
 * Symfony's Retriever switches to a HybridQuery whenever the store supports one, which halves the
 * cosine scores and appends unscored keyword matches beyond maxItems (see docs/FEEDBACK.md).
 */
final readonly class DocsRetriever implements RetrieverInterface
{
    public function __construct(
        private StoreInterface $store,
        private HashingVectorizer $vectorizer,
    ) {
    }

    public function retrieve(string $query, array $options = []): iterable
    {
        $vector = $this->vectorizer->vectorize($query);
        \assert($vector instanceof Vector);

        return $this->store->query(new VectorQuery($vector), ['maxItems' => \is_int($options['maxItems'] ?? null) ? $options['maxItems'] : 3]);
    }
}
