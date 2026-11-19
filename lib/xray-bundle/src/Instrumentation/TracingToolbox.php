<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use XRay\Event\RunError;
use XRay\Event\ToolCallFinished;
use XRay\Event\ToolCallStarted;
use XRay\Recorder\Recorder;
use XRay\Instrumentation\Mcp\McpToolDirectory;

/**
 * Times every tool execution and records its result or failure.
 *
 * Decorates the agent's toolbox from the inside (below a fault tolerant toolbox), so a failing
 * tool is seen as the exception it is, before it is turned into a message for the model.
 */
final readonly class TracingToolbox implements ToolboxInterface
{
    public function __construct(
        private ToolboxInterface $inner,
        private Recorder $recorder,
        private McpToolDirectory $mcpTools,
    ) {
    }

    public function getTools(): array
    {
        return $this->inner->getTools();
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        $context = $this->recorder->context();
        $run = $context->current();
        if (null === $run) {
            return $this->inner->execute($toolCall);
        }

        $callId = $toolCall->getId();
        $server = $this->mcpTools->serverOf($toolCall->getName());
        if (null !== $server) {
            $run->awaitingMcp[$callId] = ['name' => $toolCall->getName(), 'arguments' => $toolCall->getArguments()];
        }
        $this->recorder->emit(new ToolCallStarted($callId, $toolCall->getName(), null === $server ? 'local' : 'mcp', $server));
        $startedAt = $context->now();

        try {
            $result = $this->inner->execute($toolCall);
        } catch (\Throwable $e) {
            ++$run->errors;
            ++$run->toolCalls;
            $this->recorder->emit(new ToolCallFinished($callId, false, null, self::ms($context->now() - $startedAt), $e->getMessage()));
            $this->recorder->emit(new RunError(null === $server ? 'tool' : 'mcp', $e->getMessage(), $callId));
            throw $e;
        } finally {
            unset($run->awaitingMcp[$callId]);
        }

        ++$run->toolCalls;
        $this->recorder->emit(new ToolCallFinished($callId, true, self::normalize($result->getResult()), self::ms($context->now() - $startedAt)));

        return $result;
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return '…';
        }
        if (null === $value || \is_scalar($value)) {
            return $value;
        }
        if (\is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::normalize($v, $depth + 1), $value);
        }
        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize(), $depth + 1);
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return self::normalize(get_object_vars($value), $depth + 1);
    }

    private static function ms(int $ns): int
    {
        return intdiv(max(0, $ns), 1_000_000);
    }
}
