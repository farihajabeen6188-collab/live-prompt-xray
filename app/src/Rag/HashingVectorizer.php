<?php

declare(strict_types=1);

namespace App\Rag;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\DocumentInterface;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * A local, deterministic embedder (feature hashing of word counts), so the RAG demo
 * works with zero API keys. Real cosine distances, computed by the store; no model involved.
 */
final class HashingVectorizer implements VectorizerInterface
{
    private const int DIMENSIONS = 512;
    private const array STOPWORDS = ['the', 'and', 'for', 'are', 'can', 'with', 'our', 'your', 'you', 'what', 'about', 'how', 'all', 'any', 'per', 'get', 'has', 'have', 'this', 'that', 'everything', 'know', 'tell'];

    public function vectorize(string|\Stringable|DocumentInterface|array $values, array $options = []): Vector|VectorDocument|array
    {
        if (\is_array($values)) {
            return array_map(fn (string|\Stringable|DocumentInterface $value): Vector|VectorDocument => $this->vectorizeOne($value), $values);
        }

        return $this->vectorizeOne($values);
    }

    private function vectorizeOne(string|\Stringable|DocumentInterface $value): Vector|VectorDocument
    {
        if ($value instanceof TextDocument) {
            return new VectorDocument($value->getId(), $this->embed($value->getContent()), $value->getMetadata());
        }
        if ($value instanceof DocumentInterface) {
            throw new \InvalidArgumentException(\sprintf('Cannot vectorize a "%s".', $value::class));
        }

        return $this->embed((string) $value);
    }

    private function embed(string $text): Vector
    {
        $counts = [];
        foreach (preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [] as $word) {
            if (\strlen($word) < 3 || \in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $word = preg_replace('/(ies|es|s)$/', '', $word) ?? $word;
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        $vector = array_fill(0, self::DIMENSIONS, 0.0);
        foreach ($counts as $word => $count) {
            $hash = crc32((string) $word);
            $vector[$hash % self::DIMENSIONS] += (($hash >> 16) & 1 ? 1.0 : -1.0) * (1.0 + log($count));
        }
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        return new Vector($norm > 0.0 ? array_map(static fn (float $v): float => $v / $norm, $vector) : $vector);
    }
}
