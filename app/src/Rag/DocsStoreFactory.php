<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\InMemory\Store;

/**
 * Builds the in-memory store of the Nimbus Cloud docs (corpus/*.md), one document per file.
 */
final readonly class DocsStoreFactory
{
    public function __construct(
        private HashingVectorizer $vectorizer,
        private string $corpusDirectory,
    ) {
    }

    public function create(): Store
    {
        $store = new Store();
        $files = glob($this->corpusDirectory.'/*.md') ?: [];
        sort($files);
        foreach ($files as $file) {
            $content = trim((string) file_get_contents($file));
            $title = preg_match('/^#\s+(.+)$/m', $content, $m) ? trim($m[1]) : basename($file, '.md');
            $text = trim((string) preg_replace('/^#.*$/m', '', $content));
            $document = $this->vectorizer->vectorize(new TextDocument(basename($file, '.md'), $title.'. '.$text, new Metadata([
                Metadata::KEY_TEXT => $text,
                Metadata::KEY_TITLE => $title,
                Metadata::KEY_SOURCE => 'corpus/'.basename($file),
            ])));
            \assert($document instanceof VectorDocument);
            $store->add($document);
        }

        return $store;
    }
}
