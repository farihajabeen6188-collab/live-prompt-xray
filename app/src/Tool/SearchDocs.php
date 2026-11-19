<?php

declare(strict_types=1);

namespace App\Tool;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A local tool (not MCP): lets the model search the Nimbus docs in the middle of a run.
 */
#[AsTool('search_docs', 'Searches the Nimbus Cloud documentation and returns the closest passages.')]
final readonly class SearchDocs
{
    public function __construct(
        #[Autowire(service: 'app.docs_retriever')]
        private RetrieverInterface $retriever,
    ) {
    }

    /**
     * @param string $query what to look for, e.g. "annual plan discount"
     *
     * @return list<array{id: string, text: string}>
     */
    public function __invoke(string $query): array
    {
        $hits = [];
        foreach ($this->retriever->retrieve($query, ['maxItems' => 2]) as $document) {
            $hits[] = ['id' => (string) $document->getId(), 'text' => (string) $document->getMetadata()->getText()];
        }

        return $hits;
    }
}
