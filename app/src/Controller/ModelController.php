<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\ModelConnection;
use App\Model\ModelSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Connect the demo agent to any OpenAI-compatible API from the console: presets, save, test, disconnect.
 * The API key is stored server-side and never sent back to the browser.
 */
#[Route('/demo/model')]
final readonly class ModelController
{
    /** Defaults only: the model names change over time, the "Test" button lists what the key can use. */
    private const array PRESETS = [
        ['id' => 'openai', 'label' => 'OpenAI', 'baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini', 'needsKey' => true],
        ['id' => 'openrouter', 'label' => 'OpenRouter', 'baseUrl' => 'https://openrouter.ai/api/v1', 'model' => 'openai/gpt-4o-mini', 'needsKey' => true],
        ['id' => 'groq', 'label' => 'Groq', 'baseUrl' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile', 'needsKey' => true],
        ['id' => 'gemini', 'label' => 'Google Gemini', 'baseUrl' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.0-flash', 'needsKey' => true],
        ['id' => 'mistral', 'label' => 'Mistral', 'baseUrl' => 'https://api.mistral.ai/v1', 'model' => 'mistral-small-latest', 'needsKey' => true],
        ['id' => 'deepseek', 'label' => 'DeepSeek', 'baseUrl' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat', 'needsKey' => true],
        ['id' => 'together', 'label' => 'Together AI', 'baseUrl' => 'https://api.together.xyz/v1', 'model' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo', 'needsKey' => true],
        ['id' => 'ollama', 'label' => 'Ollama (this computer)', 'baseUrl' => 'http://host.docker.internal:11434/v1', 'model' => 'qwen3:4b', 'needsKey' => false],
        ['id' => 'lmstudio', 'label' => 'LM Studio (this computer)', 'baseUrl' => 'http://host.docker.internal:1234/v1', 'model' => 'local-model', 'needsKey' => false],
        ['id' => 'custom', 'label' => 'Other OpenAI-compatible API', 'baseUrl' => '', 'model' => '', 'needsKey' => false],
    ];

    public function __construct(
        private ModelSettings $settings,
        private HttpClientInterface $httpClient,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return new JsonResponse(['connected' => null !== $this->settings->connection(), 'connection' => $this->settings->connection()?->toPublicArray(), 'presets' => self::PRESETS, 'locked' => $this->settings->isLocked()]);
    }

    private function locked(): ?JsonResponse
    {
        return $this->settings->isLocked()
            ? new JsonResponse(['ok' => false, 'error' => 'This is the public demo: connecting a model is disabled. Run X-Ray locally (docker compose up) to use your own key.'], Response::HTTP_FORBIDDEN)
            : null;
    }

    #[Route('', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        if (null !== $locked = $this->locked()) {
            return $locked;
        }
        $data = self::input($request);
        if (null === $data) {
            return new JsonResponse(['error' => 'Send {"provider", "baseUrl" (http or https), "model", "apiKey"?}.'], Response::HTTP_BAD_REQUEST);
        }
        $current = $this->settings->connection();
        // An empty key keeps the stored one for the same endpoint, so editing the model needs no re-typing.
        $apiKey = '' === $data['apiKey'] && null !== $current && $current->baseUrl === $data['baseUrl'] ? $current->apiKey : $data['apiKey'];
        $this->settings->save(new ModelConnection($data['provider'], $data['baseUrl'], $apiKey, $data['model']));

        return $this->show();
    }

    #[Route('', methods: ['DELETE'])]
    public function disconnect(): JsonResponse
    {
        if (null !== $locked = $this->locked()) {
            return $locked;
        }
        $this->settings->clear();

        return $this->show();
    }

    /**
     * Lists the models the endpoint offers (GET {baseUrl}/models): proves the URL and the key work.
     */
    #[Route('/test', methods: ['POST'])]
    public function test(Request $request): JsonResponse
    {
        if (null !== $locked = $this->locked()) {
            return $locked;
        }
        $data = self::input($request, requireModel: false);
        if (null === $data) {
            return new JsonResponse(['ok' => false, 'error' => 'Enter a base URL starting with http:// or https://.'], Response::HTTP_BAD_REQUEST);
        }
        $current = $this->settings->connection();
        $apiKey = '' === $data['apiKey'] && null !== $current && $current->baseUrl === $data['baseUrl'] ? $current->apiKey : $data['apiKey'];

        $startedAt = hrtime(true);
        try {
            $response = $this->httpClient->request('GET', rtrim($data['baseUrl'], '/').'/models', ['auth_bearer' => '' === $apiKey ? null : $apiKey, 'timeout' => 10]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            return new JsonResponse(['ok' => false, 'error' => 'Could not reach the API: '.$e->getMessage()]);
        }
        $latency = intdiv(hrtime(true) - $startedAt, 1_000_000);
        if ($status >= 400) {
            $message = \is_array($body['error'] ?? null) ? (string) ($body['error']['message'] ?? '') : (string) ($body['error'] ?? $body['message'] ?? '');

            return new JsonResponse(['ok' => false, 'error' => \sprintf('HTTP %d%s', $status, '' === $message ? '' : ': '.$message), 'latencyMs' => $latency]);
        }
        $models = [];
        foreach (\is_array($body['data'] ?? null) ? $body['data'] : (\is_array($body['models'] ?? null) ? $body['models'] : []) as $model) {
            $id = \is_array($model) ? ($model['id'] ?? $model['name'] ?? null) : null;
            if (\is_string($id)) {
                $models[] = str_starts_with($id, 'models/') ? substr($id, 7) : $id;
            }
        }
        sort($models);

        return new JsonResponse(['ok' => true, 'latencyMs' => $latency, 'models' => \array_slice($models, 0, 200)]);
    }

    /**
     * @return array{provider: string, baseUrl: string, apiKey: string, model: string}|null
     */
    private static function input(Request $request, bool $requireModel = true): ?array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return null;
        }
        $baseUrl = trim(\is_string($data['baseUrl'] ?? null) ? $data['baseUrl'] : '');
        $model = trim(\is_string($data['model'] ?? null) ? $data['model'] : '');
        $scheme = parse_url($baseUrl, \PHP_URL_SCHEME);
        if (!\in_array($scheme, ['http', 'https'], true) || false === filter_var($baseUrl, \FILTER_VALIDATE_URL) || ($requireModel && '' === $model) || mb_strlen($model) > 200) {
            return null;
        }

        return [
            'provider' => preg_replace('/[^a-z0-9_-]/', '', strtolower(\is_string($data['provider'] ?? null) ? $data['provider'] : 'custom')) ?: 'custom',
            'baseUrl' => $baseUrl,
            'apiKey' => trim(\is_string($data['apiKey'] ?? null) ? $data['apiKey'] : ''),
            'model' => $model,
        ];
    }
}
