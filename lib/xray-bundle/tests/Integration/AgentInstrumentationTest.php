<?php

declare(strict_types=1);

namespace XRay\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use XRay\Instrumentation\Mcp\McpToolDirectory;
use XRay\Instrumentation\TracingAgent;
use XRay\Instrumentation\TracingPlatform;
use XRay\Instrumentation\TracingToolbox;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;
use XRay\Recorder\Redactor;
use XRay\Recorder\RunContext;
use XRay\Storage\SqliteTraceStore;
use XRay\Tests\Support\CollectingPublisher;

/**
 * A real Symfony AI Agent with a real Toolbox, run through the X-Ray decorators.
 * Only the model is scripted (Symfony AI's own MockPlatformFactory).
 */
final class AgentInstrumentationTest extends TestCase
{
    public function testAToolCallingRunProducesTheExactEventSequence(): void
    {
        $turns = 0;
        [$agent, $publisher, $context] = $this->agent(static function () use (&$turns): ResultInterface {
            return 1 === ++$turns
                ? new ToolCallResult([new ToolCall('call_1', 'multiply', ['a' => 6, 'b' => 7])])
                : new StreamResult((static function (): \Generator {
                    yield new TextDelta('The answer ');
                    yield new TextDelta('is 42.');
                    yield new TokenUsage(promptTokens: 30, completionTokens: 5);
                })());
        });
        $context->prepare('run_integration1', 'math', 'demo');

        $text = $agent->call('What is 6 times 7?', ['stream' => true]);
        $streamed = implode('', array_map(static fn (DeltaInterface $d): string => $d instanceof TextDelta ? $d->getText() : '', iterator_to_array($text->asStream(), false)));

        self::assertSame('The answer is 42.', $streamed);
        self::assertSame([
            'run.started',
            'prompt.sent',
            'tool.call.requested',
            'usage.reported',
            'tool.call.started',
            'tool.call.finished',
            'prompt.sent',
            'token.chunk',
            'token.chunk',
            'usage.reported',
            'run.finished',
        ], $publisher->types());
        self::assertSame(range(1, 11), array_column($publisher->events, 'seq'));

        $events = $publisher->events;
        self::assertSame(['prompt' => 'What is 6 times 7?', 'agent' => 'test', 'model' => 'scripted', 'mode' => 'demo', 'scenario' => 'math'], $events[0]['data']);
        self::assertSame(['callId' => 'call_1', 'name' => 'multiply', 'arguments' => ['a' => 6, 'b' => 7], 'turn' => 1], $events[2]['data']);
        self::assertSame(['callId' => 'call_1', 'name' => 'multiply', 'via' => 'local', 'server' => null], $events[4]['data']);
        self::assertTrue($events[5]['data']['ok']);
        self::assertSame(42, $events[5]['data']['result']);
        self::assertSame(['multiply', 'explode'], array_column($events[1]['data']['tools'], 'name'));
        self::assertSame(['user', 'assistant', 'tool'], array_column($events[6]['data']['messages'], 'role'));
        self::assertSame(['inputTokens' => 30, 'outputTokens' => 5], array_intersect_key($events[9]['data'], ['inputTokens' => 0, 'outputTokens' => 0]));
        self::assertFalse($events[9]['data']['estimated']);
        self::assertSame(0.000255, $events[9]['data']['estimatedCostUsd']);
        self::assertSame(['turns' => 2, 'toolCalls' => 1, 'finalText' => 'The answer is 42.', 'ok' => true], array_intersect_key($events[10]['data'], ['turns' => 0, 'toolCalls' => 0, 'finalText' => 0, 'ok' => 0]));
    }

    public function testAFailingToolIsRecordedAndTheRunRecovers(): void
    {
        $turns = 0;
        [$agent, $publisher, $context] = $this->agent(static function () use (&$turns): ResultInterface {
            return 1 === ++$turns
                ? new ToolCallResult([new ToolCall('call_9', 'explode', [])])
                : new TextResult('The tool failed, sorry.');
        });
        $context->prepare('run_integration2');

        self::assertSame('The tool failed, sorry.', $agent->call('Break something')->asText());

        $types = $publisher->types();
        self::assertSame(['run.started', 'prompt.sent', 'tool.call.requested', 'usage.reported', 'tool.call.started', 'tool.call.finished', 'run.error', 'prompt.sent', 'token.chunk', 'usage.reported', 'run.finished'], $types);
        $finished = $publisher->events[5]['data'];
        self::assertFalse($finished['ok']);
        self::assertSame('Execution of tool "explode" failed with error: Boom.', $finished['error']);
        self::assertSame(['where' => 'tool', 'message' => 'Execution of tool "explode" failed with error: Boom.', 'callId' => 'call_9'], $publisher->events[6]['data']);
        self::assertTrue($publisher->events[10]['data']['ok']);
        self::assertTrue($publisher->events[9]['data']['estimated']);
    }

    public function testTheAgentRunsUnchangedWhenTheRecorderCannotPublish(): void
    {
        [$agent, $publisher, $context] = $this->agent(static fn (): ResultInterface => new TextResult('fine'));
        $publisher->fail = true;
        $context->prepare('run_integration3');

        self::assertSame('fine', $agent->call('hello')->asText());
    }

    /**
     * @return array{TracingAgent, CollectingPublisher, RunContext}
     */
    private function agent(\Closure $script): array
    {
        $context = new RunContext();
        $publisher = new CollectingPublisher();
        $recorder = new Recorder($context, new Redactor(), new SqliteTraceStore(':memory:'), $publisher);

        $platform = new TracingPlatform(MockPlatformFactory::createPlatform($script), $recorder, new CostEstimator(['scripted' => ['input' => 5.0, 'output' => 21.0]]));
        $toolbox = new FaultTolerantToolbox(new TracingToolbox(new Toolbox([new MultiplyTool(), new ExplodingTool()]), $recorder, new McpToolDirectory()));
        $agent = new TracingAgent(new Agent($platform, 'scripted', name: 'test', toolbox: $toolbox), $recorder);

        return [$agent, $publisher, $context];
    }
}

#[AsTool('multiply', 'Multiplies two integers')]
final class MultiplyTool
{
    public function __invoke(int $a, int $b): int
    {
        return $a * $b;
    }
}

#[AsTool('explode', 'Always fails')]
final class ExplodingTool
{
    public function __invoke(): string
    {
        throw new \RuntimeException('Boom.');
    }
}
