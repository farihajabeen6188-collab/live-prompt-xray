<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\Tool;
use XRay\Event\PromptSent;
use XRay\Event\RunError;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;

/**
 * Records every model turn: the exact messages and tool schemas sent, streamed tokens,
 * requested tool calls, usage and errors.
 *
 * Observation happens through the public DeferredResult callbacks and a stream listener,
 * so the result the agent receives is untouched.
 */
final readonly class TracingPlatform implements PlatformInterface
{
    public function __construct(
        private PlatformInterface $inner,
        private Recorder $recorder,
        private CostEstimator $costs = new CostEstimator(),
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $run = $this->recorder->context()->current();
        if (null === $run) {
            return $this->inner->invoke($model, $input, $options);
        }

        $turn = ++$run->turn;
        $modelName = $model instanceof Model ? $model->getName() : $model;
        $startedAt = $this->recorder->context()->now();
        $estimatedInput = 0;
        try {
            $messages = new MessageDescriber($this->recorder->redactor())->describeInput($input);
            $estimatedInput = array_sum(array_map(static fn (array $m): int => CostEstimator::estimateTokens($m['content']), $messages));
            $this->recorder->emit(new PromptSent($messages, $this->tools($options), $turn, $modelName));
        } catch (\Throwable $e) {
            $this->logger->warning('X-Ray could not describe the prompt: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }

        try {
            $deferred = $this->inner->invoke($model, $input, $options);
        } catch (\Throwable $e) {
            $this->fail($e);
            throw $e;
        }

        $turnRecorder = new TurnRecorder($this->recorder, $this->costs, $modelName, $turn, $startedAt, $estimatedInput);
        $deferred->onConvert(function (ResultInterface $result) use ($turnRecorder, $deferred): ResultInterface {
            $this->observe($result, $turnRecorder, $deferred);

            return $result;
        });
        $deferred->onError(function (\Throwable $e) use ($turnRecorder): void {
            $this->fail($e);
            $turnRecorder->close();
        });

        return $deferred;
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->inner->getModelCatalog();
    }

    private function observe(ResultInterface $result, TurnRecorder $turn, DeferredResult $deferred): void
    {
        try {
            if ($result instanceof StreamResult) {
                $result->addListener(new TurnStreamListener($turn, $this->recorder));

                return;
            }
            if ($result instanceof ToolCallResult) {
                $turn->toolCalls($result->getContent());
            } elseif ($result instanceof MultiPartResult) {
                $turn->text($result->asText());
                if (null !== $toolCalls = $result->asToolCallResult()) {
                    $turn->toolCalls($toolCalls->getContent());
                }
            } elseif ($result instanceof TextResult) {
                $turn->text($result->getContent());
            }
            $usage = $deferred->getMetadata()->get('token_usage') ?? $result->getMetadata()->get('token_usage');
            if ($usage instanceof TokenUsageInterface) {
                $turn->usage($usage);
            }
            $turn->close();
        } catch (\Throwable $e) {
            $this->logger->warning('X-Ray could not observe a model result: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function fail(\Throwable $e): void
    {
        $run = $this->recorder->context()->current();
        if (null !== $run) {
            ++$run->errors;
        }
        $this->recorder->emit(new RunError('platform', $e->getMessage()));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<array{name: string, description: string}>
     */
    private function tools(array $options): array
    {
        $tools = [];
        foreach (\is_array($options['tools'] ?? null) ? $options['tools'] : [] as $tool) {
            if ($tool instanceof Tool) {
                $tools[] = ['name' => $tool->getName(), 'description' => $tool->getDescription()];
            } elseif (\is_array($tool) && \is_string($tool['name'] ?? $tool['type'] ?? null)) {
                $tools[] = ['name' => (string) ($tool['name'] ?? $tool['type']), 'description' => (string) ($tool['description'] ?? '')];
            }
        }

        return $tools;
    }
}
