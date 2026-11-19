<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * The scripted platform builds its result directly; this hands it through unchanged.
 */
final class PassThroughConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $object = $result->getObject();
        if (!$object instanceof ResultInterface) {
            throw new \LogicException('The scripted platform always produces a result object.');
        }

        return $object;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
