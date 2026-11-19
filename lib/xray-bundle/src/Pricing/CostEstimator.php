<?php

declare(strict_types=1);

namespace XRay\Pricing;

/**
 * Estimates the cost of a model turn from a configured price table (USD per 1M tokens).
 * Models without a configured price have no estimate: X-Ray never invents a price.
 */
final readonly class CostEstimator
{
    /**
     * @param array<string, array{input: float, output: float}> $prices
     */
    public function __construct(private array $prices = [])
    {
    }

    public function estimate(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $price = $this->prices[$model] ?? null;
        if (null === $price) {
            return null;
        }

        return round(($inputTokens * $price['input'] + $outputTokens * $price['output']) / 1_000_000, 6);
    }

    /**
     * Rough token count for providers that report no usage (about 4 characters per token).
     */
    public static function estimateTokens(string $text): int
    {
        return '' === $text ? 0 : max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
