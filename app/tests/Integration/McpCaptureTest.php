<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\AI\Agent\AgentInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use XRay\Event\Envelope;
use XRay\Recorder\RunContext;
use XRay\Storage\TraceStore;

/**
 * The real demo agent, toolbox, MCP client and MCP server (as a STDIO child process in tests).
 * Only the model is scripted.
 */
final class McpCaptureTest extends KernelTestCase
{
    public function testMcpFramesAreCapturedAndCorrelatedWithTheToolCall(): void
    {
        $events = $this->runScenario('run_mcpcapture1', 'Do I need an umbrella in Paris tomorrow?');

        $frames = array_values(array_filter($events, static fn (Envelope $e): bool => 'mcp.frame' === $e->type));
        $outMethods = array_map(static fn (Envelope $e): ?string => $e->data['method'], array_filter($frames, static fn (Envelope $e): bool => 'out' === $e->data['direction']));
        self::assertNotEmpty(array_intersect(['initialize', 'server/discover'], $outMethods), 'The connection handshake is captured.');
        self::assertContains('tools/list', $outMethods);
        self::assertContains('tools/call', $outMethods);

        $requested = self::first($events, 'tool.call.requested');
        self::assertSame('demo_get_weather', $requested->data['name']);
        $callId = $requested->data['callId'];

        $started = self::first($events, 'tool.call.started');
        self::assertSame(['callId' => $callId, 'name' => 'demo_get_weather', 'via' => 'mcp', 'server' => 'demo'], $started->data);

        $call = array_values(array_filter($frames, static fn (Envelope $e): bool => 'tools/call' === $e->data['method']));
        self::assertCount(2, $call, 'One request and one response.');
        self::assertSame(['out', 'in'], [$call[0]->data['direction'], $call[1]->data['direction']]);
        self::assertSame([$callId, $callId], [$call[0]->data['correlatesTo'], $call[1]->data['correlatesTo']]);
        self::assertSame($call[0]->data['id'], $call[1]->data['id']);
        self::assertIsInt($call[1]->data['latencyMs']);
        self::assertSame('get_weather', $call[0]->data['payload']['params']['name']);

        $finished = self::first($events, 'tool.call.finished');
        self::assertTrue($finished->data['ok']);
        self::assertSame('Paris', $finished->data['result']['city']);
        self::assertGreaterThan($call[1]->seq, $finished->seq);
        self::assertSame('run.finished', end($events)->type);
    }

    public function testAnMcpErrorIsCapturedAndTheRetryRecovers(): void
    {
        $events = $this->runScenario('run_mcpcapture2', 'Look up the Q4 report topic and check service 42');

        $flaky = array_values(array_filter($events, static fn (Envelope $e): bool => 'tool.call.finished' === $e->type && \in_array($e->data['callId'], self::callIdsOf($events, 'demo_flaky_service'), true)));
        self::assertSame([false, true], array_map(static fn (Envelope $e): bool => $e->data['ok'], $flaky));

        $errors = array_values(array_filter($events, static fn (Envelope $e): bool => 'mcp.frame' === $e->type && 'error' === $e->data['kind']));
        self::assertCount(1, $errors);
        self::assertSame($flaky[0]->data['callId'], $errors[0]->data['correlatesTo']);
        self::assertTrue(end($events)->data['ok']);
        self::assertStringContainsString('healthy', (string) end($events)->data['finalText']);
    }

    /**
     * @return list<Envelope>
     */
    private function runScenario(string $runId, string $prompt): array
    {
        self::bootKernel();
        $container = static::getContainer();
        $container->get(RunContext::class)->prepare($runId, null, 'demo');
        $agent = $container->get('ai.agent.demo');
        \assert($agent instanceof AgentInterface);

        $agent->call($prompt, ['stream' => true])->asText();

        $store = $container->get(TraceStore::class);
        \assert($store instanceof TraceStore);

        return $store->events($runId);
    }

    /**
     * @param list<Envelope> $events
     */
    private static function first(array $events, string $type): Envelope
    {
        foreach ($events as $event) {
            if ($type === $event->type) {
                return $event;
            }
        }
        self::fail(\sprintf('No "%s" event.', $type));
    }

    /**
     * @param list<Envelope> $events
     *
     * @return list<string>
     */
    private static function callIdsOf(array $events, string $tool): array
    {
        return array_values(array_map(
            static fn (Envelope $e): string => (string) $e->data['callId'],
            array_filter($events, static fn (Envelope $e): bool => 'tool.call.requested' === $e->type && $tool === $e->data['name']),
        ));
    }
}
