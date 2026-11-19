<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;
use XRay\Event\RunError;
use XRay\Event\RunFinished;
use XRay\Event\RunStarted;
use XRay\Recorder\Recorder;

/**
 * Opens a run when the agent is called and closes it once its execution is consumed.
 *
 * The inner execution is passed through update by update, so streaming, progress callbacks
 * and results behave exactly as without X-Ray.
 */
final readonly class TracingAgent implements AgentInterface
{
    public function __construct(
        private AgentInterface $inner,
        private Recorder $recorder,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        if ($this->recorder->isRecording()) {
            // A sub-agent call is part of the run that is already being recorded.
            return $this->inner->call($input, $options);
        }

        $labels = $this->recorder->context()->takePrepared();
        $model = method_exists($this->inner, 'getModel') ? (string) $this->inner->getModel() : 'unknown';
        $this->recorder->beginRun($labels['runId'], new RunStarted(self::promptOf($input), $this->inner->getName(), $model, $labels['mode'], $labels['scenario']));

        try {
            $execution = $this->inner->call($input, $options);
        } catch (\Throwable $e) {
            $this->recorder->emit(new RunError('agent', $e->getMessage()));
            $this->finish(false, '');
            throw $e;
        }

        return new Execution(fn (): \Generator => $this->observe($execution), $execution->isStreamed());
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    /**
     * @return \Generator<int, mixed>
     */
    private function observe(ExecutionInterface $execution): \Generator
    {
        $ok = false;
        $finalText = null;
        try {
            foreach ($execution as $update) {
                if ($update instanceof ResultUpdate) {
                    // The final answer: a caller reading only the result stops iterating here.
                    $ok = true;
                    if ($update->getResult() instanceof TextResult) {
                        $finalText = $update->getResult()->getContent();
                    }
                }
                yield $update;
            }
        } catch (\Throwable $e) {
            $this->recorder->emit(new RunError('agent', $e->getMessage()));
            throw $e;
        } finally {
            $this->finish($ok, $finalText);
        }
    }

    private function finish(bool $ok, ?string $finalText): void
    {
        $context = $this->recorder->context();
        $run = $context->current();
        if (null === $run) {
            return;
        }
        $this->recorder->endRun(new RunFinished(
            durationMs: $run->elapsedMs($context->now()),
            turns: $run->turn,
            toolCalls: $run->toolCalls,
            inputTokens: $run->inputTokens,
            outputTokens: $run->outputTokens,
            estimatedCostUsd: $run->costUsd,
            finalText: $finalText ?? $run->finalText,
            ok: $ok,
        ));
    }

    private static function promptOf(string|MessageBag|UserMessage $input): string
    {
        if (\is_string($input)) {
            return $input;
        }
        if ($input instanceof UserMessage) {
            return $input->asText() ?? '';
        }

        return $input->getUserMessage()?->asText() ?? '';
    }
}
