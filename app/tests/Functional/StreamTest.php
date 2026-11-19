<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use XRay\Event\RunFinished;
use XRay\Event\RunStarted;
use XRay\Event\TokenChunk;
use XRay\Recorder\Recorder;
use XRay\Recorder\RunContext;

final class StreamTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testTheLiveStreamDeliversARunsEventsInOrderAndEndsWithTheRun(): void
    {
        $runId = $this->recordRun(['Hel', 'lo ', 'world']);

        $this->client->request('GET', \sprintf('/_xray/runs/%s/stream', $runId));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/event-stream; charset=UTF-8');
        $events = self::parse($this->client->getInternalResponse()->getContent());
        self::assertSame([1, 2, 3, 4, 5], array_column($events, 'seq'));
        self::assertSame(['run.started', 'token.chunk', 'token.chunk', 'token.chunk', 'run.finished'], array_column($events, 'type'));
        self::assertSame('lo ', $events[2]['data']['text']);
    }

    public function testAReconnectingClientResumesAfterTheLastEventId(): void
    {
        $runId = $this->recordRun(['a', 'b']);

        $this->client->request('GET', \sprintf('/_xray/runs/%s/stream', $runId), server: ['HTTP_LAST_EVENT_ID' => '2']);

        self::assertSame([3, 4], array_column(self::parse($this->client->getInternalResponse()->getContent()), 'seq'));
    }

    public function testReplayPlaysAStoredRunBackAtTheRequestedSpeed(): void
    {
        $runId = $this->recordRun(['x', 'y'], stepMs: 400);

        $startedAt = microtime(true);
        $this->client->request('GET', \sprintf('/_xray/runs/%s/replay?speed=4', $runId));
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $events = self::parse($this->client->getInternalResponse()->getContent());
        self::assertSame([1, 2, 3, 4], array_column($events, 'seq'));
        // 2 gaps of 400 ms at 4x speed: about 200 ms instead of the original 800 ms.
        self::assertGreaterThan(180, $elapsedMs);
        self::assertLessThan(700, $elapsedMs);
    }

    public function testUnknownRunsAreNotFound(): void
    {
        $this->client->request('GET', '/_xray/runs/run_doesnotexist/replay');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param list<string> $chunks
     */
    private function recordRun(array $chunks, int $stepMs = 0): string
    {
        $now = 0;
        $container = static::getContainer();
        $runId = RunContext::newRunId();
        $recorder = new Recorder(
            new RunContext(static function () use (&$now): int {
                return $now;
            }),
            $container->get(\XRay\Recorder\Redactor::class),
            $container->get(\XRay\Storage\TraceStore::class),
            $container->get('xray.publisher'),
        );
        $recorder->beginRun($runId, new RunStarted('hi', 'agent', 'model', 'demo'));
        foreach ($chunks as $chunk) {
            $now += $stepMs * 1_000_000;
            $recorder->emit(new TokenChunk(1, $chunk));
        }
        $recorder->endRun(new RunFinished(\count($chunks) * $stepMs, 1, 0, 1, 1, null, implode('', $chunks), true));

        return $runId;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parse(string|false $body): array
    {
        $events = [];
        foreach (explode("\n\n", (string) $body) as $block) {
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $events[] = json_decode(substr($line, 6), true, 512, \JSON_THROW_ON_ERROR);
                }
            }
        }

        return $events;
    }
}
