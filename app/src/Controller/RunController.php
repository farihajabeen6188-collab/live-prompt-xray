<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\ModelSettings;
use App\Platform\ScriptBook;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use XRay\Recorder\RunContext;

/**
 * The demo app's run endpoint, used by the X-Ray console: GET describes the model and the scenarios,
 * POST runs one chat turn (the earlier turns come as "history"). The console subscribes to the run's
 * events before posting, so it sees the run from its first event.
 */
#[Route('/demo/run')]
final readonly class RunController
{
    private const int MAX_HISTORY = 20;

    public function __construct(
        #[Autowire(service: 'ai.agent.demo')]
        private AgentInterface $agent,
        private RunContext $runs,
        private ScriptBook $scripts,
        private ModelSettings $settings,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function scenarios(): JsonResponse
    {
        return new JsonResponse([
            'mode' => $this->settings->mode(),
            'model' => $this->settings->modelName(),
            'agent' => 'demo',
            'chat' => true,
            'modelUrl' => '/demo/model',
            'scenarios' => $this->scripts->scenarios(),
        ]);
    }

    #[Route('', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = \is_array($payload) ? $payload : [];
        $prompt = trim(\is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '');
        $runId = \is_string($payload['runId'] ?? null) ? $payload['runId'] : RunContext::newRunId();
        if ('' === $prompt || mb_strlen($prompt) > 4000 || !RunContext::isValidRunId($runId)) {
            return new JsonResponse(['error' => 'Send {"prompt": "...", "runId": "run_...", "history": [...]} with a prompt of 1 to 4000 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $messages = [];
        foreach (\is_array($payload['history'] ?? null) ? \array_slice($payload['history'], -self::MAX_HISTORY) : [] as $message) {
            if (\is_array($message) && \is_string($message['content'] ?? null) && '' !== $message['content']) {
                $messages[] = 'assistant' === ($message['role'] ?? null) ? Message::ofAssistant($message['content']) : Message::ofUser($message['content']);
            }
        }
        $messages[] = Message::ofUser($prompt);

        $mode = $this->settings->mode();
        $this->runs->prepare($runId, 'demo' === $mode && $this->scripts->isScenarioPrompt($prompt) ? $this->scripts->scenarioFor($prompt) : null, $mode);
        try {
            $text = '';
            // "model" is applied by the ModelOverrideInputProcessor, so traces show the connected model.
            foreach ($this->agent->call(new MessageBag(...$messages), ['stream' => true, 'model' => $this->settings->modelName()])->asStream() as $delta) {
                $text .= $delta instanceof \Stringable ? (string) $delta : '';
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['runId' => $runId, 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['runId' => $runId, 'text' => $text]);
    }
}
