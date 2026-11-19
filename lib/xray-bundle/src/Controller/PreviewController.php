<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use XRay\Preview\PromptPreviewer;

/**
 * POST /_xray/preview {"prompt": "...", "history": [{role, content}], "agent": "name"?}
 * The pre-flight X-Ray, called by the console while the user types.
 */
final readonly class PreviewController
{
    private const int MAX_PROMPT = 4000;

    public function __construct(private PromptPreviewer $previewer)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $prompt = \is_array($payload) && \is_string($payload['prompt'] ?? null) ? trim($payload['prompt']) : '';
        if ('' === $prompt || mb_strlen($prompt) > self::MAX_PROMPT) {
            return new JsonResponse(['error' => 'Send {"prompt": "..."} with 1 to 4000 characters.'], Response::HTTP_BAD_REQUEST);
        }
        $history = [];
        foreach (\is_array($payload['history'] ?? null) ? \array_slice($payload['history'], -20) : [] as $message) {
            if (\is_array($message) && \in_array($message['role'] ?? null, ['user', 'assistant'], true) && \is_string($message['content'] ?? null)) {
                $history[] = ['role' => (string) $message['role'], 'content' => mb_substr($message['content'], 0, 8000)];
            }
        }
        $agent = \is_string($payload['agent'] ?? null) ? $payload['agent'] : null;

        try {
            return new JsonResponse($this->previewer->preview($prompt, $history, $agent));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
