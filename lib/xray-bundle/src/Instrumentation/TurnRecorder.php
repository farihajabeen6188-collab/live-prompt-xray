<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use XRay\Event\TokenChunk;
use XRay\Event\ToolCallRequested;
use XRay\Event\UsageReported;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;

/**
 * Accumulates what one model turn produced (text, tool calls, usage) and reports it.
 *
 * @internal
 */
final class TurnRecorder
{
    private string $text = '';
    private ?TokenUsageInterface $usage = null;
    private bool $closed = false;

    public function __construct(
        private readonly Recorder $recorder,
        private readonly CostEstimator $costs,
        private readonly string $model,
        private readonly int $turn,
        private readonly int $startedAtNs,
        private readonly int $estimatedInputTokens,
    ) {
    }

    public function text(string $text): void
    {
        if ('' === $text) {
            return;
        }
        $this->text .= $text;
        $this->recorder->emit(new TokenChunk($this->turn, $text));
    }

    /**
     * @param iterable<ToolCall> $toolCalls
     */
    public function toolCalls(iterable $toolCalls): void
    {
        $run = $this->recorder->context()->current();
        foreach ($toolCalls as $toolCall) {
            if (null !== $run) {
                $run->toolNames[$toolCall->getId()] = $toolCall->getName();
            }
            $this->recorder->emit(new ToolCallRequested($toolCall->getId(), $toolCall->getName(), $toolCall->getArguments(), $this->turn));
        }
    }

    public function usage(TokenUsageInterface $usage): void
    {
        $this->usage = $usage;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $reported = null !== $this->usage && (null !== $this->usage->getPromptTokens() || null !== $this->usage->getCompletionTokens());
        $input = $reported ? (int) $this->usage?->getPromptTokens() : $this->estimatedInputTokens;
        $output = $reported ? (int) $this->usage?->getCompletionTokens() : CostEstimator::estimateTokens($this->text);
        $cost = $this->costs->estimate($this->model, $input, $output);

        $context = $this->recorder->context();
        $run = $context->current();
        if (null !== $run) {
            $run->inputTokens += $input;
            $run->outputTokens += $output;
            $run->addCost($cost);
            if ('' !== $this->text) {
                $run->finalText = $this->text;
            }
        }
        $this->recorder->emit(new UsageReported($this->turn, $input, $output, $cost, intdiv(max(0, $context->now() - $this->startedAtNs), 1_000_000), !$reported));
    }
}
