<?php

declare(strict_types=1);

namespace App\Platform;

use App\Model\ModelSettings;
use Symfony\AI\Platform\Bridge\Generic\Factory;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The demo agent's platform: the scripted demo model, or, once a model is connected in the console,
 * any OpenAI-compatible chat completions API through Symfony AI's generic bridge.
 *
 * It is tagged "ai.platform", so X-Ray traces it like any configured platform.
 */
final readonly class SwitchablePlatform implements PlatformInterface
{
    public function __construct(
        private ScriptedPlatform $scripted,
        private ModelSettings $settings,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $connection = $this->settings->connection();
        if (null === $connection) {
            return $this->scripted->invoke($model, $input, $options);
        }

        $platform = Factory::createPlatform(
            rtrim($connection->baseUrl, '/'),
            '' === $connection->apiKey ? null : $connection->apiKey,
            $this->httpClient,
            supportsEmbeddings: false,
            completionsPath: '/chat/completions',
            name: $connection->provider,
        );

        return $platform->invoke($connection->model, $input, $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->scripted->getModelCatalog();
    }
}
