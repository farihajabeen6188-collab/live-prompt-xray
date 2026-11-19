<?php

declare(strict_types=1);

namespace App\Controller;

use App\Platform\PlatformSelector;
use App\Platform\ScriptBook;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use XRay\Recorder\RunContext;

/**
 * The demo app's run endpoint, used by the X-Ray console: GET lists scenarios, POST runs a prompt.
 * The console subscribes to the run's events before posting, so it sees the run from its first event.
 */
#[Route('/demo/run')]
final readonly class RunController
{
    public function __construct(
        #[Autowire(service: 'ai.agent.demo')]
        private AgentInterface $agent,
        private RunContext $runs,
        private ScriptBook $scripts,
        private PlatformSelector $platforms,
        #[Autowire(env: 'XRAY_MODEL')]
        private string $model,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function scenarios(): JsonResponse
    {
        return new JsonResponse(['mode' => $this->platforms->mode(), 'model' => $this->model, 'scenarios' => $this->scripts->scenarios()]);
    }

    #[Route('', methods: ['POST'])]
    public function run(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        $prompt = trim(\is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '');
        $runId = \is_string($payload['runId'] ?? null) ? $payload['runId'] : RunContext::newRunId();
        if ('' === $prompt || mb_strlen($prompt) > 2000 || !RunContext::isValidRunId($runId)) {
            return new JsonResponse(['error' => 'Send {"prompt": "...", "runId": "run_..."} with a prompt of 1 to 2000 characters.'], Response::HTTP_BAD_REQUEST);
        }

        $mode = $this->platforms->mode();
        $this->runs->prepare($runId, 'demo' === $mode ? $this->scripts->scenarioFor($prompt) : null, $mode);
        try {
            $text = '';
            foreach ($this->agent->call($prompt, ['stream' => true])->asStream() as $delta) {
                $text .= $delta instanceof \Stringable ? (string) $delta : '';
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['runId' => $runId, 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['runId' => $runId, 'text' => $text]);
    }
}
