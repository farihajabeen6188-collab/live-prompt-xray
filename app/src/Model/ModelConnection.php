<?php

declare(strict_types=1);

namespace App\Model;

/**
 * A connection to an OpenAI-compatible chat completions API (OpenAI, OpenRouter, Groq, Gemini,
 * Mistral, DeepSeek, Together, a local Ollama or LM Studio, …).
 */
final readonly class ModelConnection
{
    /** @var non-empty-string */
    public string $provider;

    /** @var non-empty-string */
    public string $model;

    public function __construct(
        string $provider,
        public string $baseUrl,
        public string $apiKey,
        string $model,
    ) {
        if ('' === $model) {
            throw new \InvalidArgumentException('A model connection needs a model name.');
        }
        $this->provider = '' === $provider ? 'custom' : $provider;
        $this->model = $model;
    }

    /**
     * What the browser may see: never the key itself.
     *
     * @return array{provider: string, baseUrl: string, model: string, keyHint: ?string}
     */
    public function toPublicArray(): array
    {
        return [
            'provider' => $this->provider,
            'baseUrl' => $this->baseUrl,
            'model' => $this->model,
            'keyHint' => '' === $this->apiKey ? null : '••••'.substr($this->apiKey, -4),
        ];
    }
}
