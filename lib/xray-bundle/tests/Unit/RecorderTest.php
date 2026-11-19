<?php

declare(strict_types=1);

namespace XRay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use XRay\Event\RunFinished;
use XRay\Event\RunStarted;
use XRay\Event\TokenChunk;
use XRay\Event\ToolCallRequested;
use XRay\Recorder\Recorder;
use XRay\Recorder\Redactor;
use XRay\Recorder\RunContext;
use XRay\Storage\SqliteTraceStore;
use XRay\Tests\Support\CollectingPublisher;
use XRay\Tests\Support\FailingTraceStore;

final class RecorderTest extends TestCase
{
    private int $now = 0;

    public function testEventsGetMonotonicSequenceNumbersAndTimestamps(): void
    {
        $publisher = new CollectingPublisher();
        $store = new SqliteTraceStore(':memory:');
        $recorder = new Recorder($this->context(), new Redactor(), $store, $publisher);

        $recorder->beginRun('run_test1', new RunStarted('hi', 'agent', 'model', 'demo'));
        $this->now = 5_000_000;
        $recorder->emit(new TokenChunk(1, 'Hel'));
        $this->now = 12_000_000;
        $recorder->emit(new TokenChunk(1, 'lo'));
        $recorder->endRun(new RunFinished(12, 1, 0, 3, 2, null, 'Hello', true));

        self::assertSame([1, 2, 3, 4], array_column($publisher->events, 'seq'));
        self::assertSame([0, 5, 12, 12], array_column($publisher->events, 'ts'));
        self::assertSame(['run.started', 'token.chunk', 'token.chunk', 'run.finished'], $publisher->types());
        self::assertSame(['run_test1'], array_values(array_unique(array_column($publisher->events, 'runId'))));

        $stored = $store->events('run_test1');
        self::assertSame([1, 2, 3, 4], array_map(static fn ($e) => $e->seq, $stored));
        self::assertSame('finished', $store->run('run_test1')?->status);
        self::assertSame([3, 4], array_map(static fn ($e) => $e->seq, $store->events('run_test1', 2)));
    }

    public function testEventsOutsideARunAreIgnored(): void
    {
        $publisher = new CollectingPublisher();
        $recorder = new Recorder($this->context(), new Redactor(), new SqliteTraceStore(':memory:'), $publisher);

        $recorder->emit(new TokenChunk(1, 'orphan'));

        self::assertSame([], $publisher->events);
        self::assertFalse($recorder->isRecording());
    }

    public function testSecretsAreRedactedBeforeStorageAndTransport(): void
    {
        $publisher = new CollectingPublisher();
        $store = new SqliteTraceStore(':memory:');
        $recorder = new Recorder($this->context(), new Redactor(), $store, $publisher);

        $recorder->beginRun('run_test2', new RunStarted('use Bearer abcdefghijklmnop please', 'agent', 'model', 'demo'));
        $recorder->emit(new ToolCallRequested('call_1', 'deploy', ['api_key' => 'sk-live-123', 'region' => 'eu'], 1));

        $published = $publisher->events[1]['data'];
        self::assertIsArray($published);
        self::assertSame(['api_key' => Redactor::MASK, 'region' => 'eu'], $published['arguments']);
        self::assertSame(['api_key' => Redactor::MASK, 'region' => 'eu'], $store->events('run_test2')[1]->data['arguments']);
        self::assertStringNotContainsString('abcdefghijklmnop', (string) $store->run('run_test2')?->prompt);
        self::assertStringNotContainsString('abcdefghijklmnop', (string) json_encode($publisher->events[0]));
    }

    public function testAFailingStoreAndHubNeverReachTheCaller(): void
    {
        $publisher = new CollectingPublisher();
        $publisher->fail = true;
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->warnings[] = (string) $message;
            }
        };
        $recorder = new Recorder($this->context(), new Redactor(), new FailingTraceStore(), $publisher, $logger);

        $recorder->beginRun('run_test3', new RunStarted('hi', 'agent', 'model', 'demo'));
        $recorder->emit(new TokenChunk(1, 'still fine'));
        $recorder->endRun(new RunFinished(1, 1, 0, 0, 0, null, 'still fine', true));

        self::assertNotEmpty($logger->warnings);
        self::assertFalse($recorder->isRecording());
    }

    public function testAFailingHubDoesNotStopStorage(): void
    {
        $publisher = new CollectingPublisher();
        $publisher->fail = true;
        $store = new SqliteTraceStore(':memory:');
        $recorder = new Recorder($this->context(), new Redactor(), $store, $publisher);

        $recorder->beginRun('run_test4', new RunStarted('hi', 'agent', 'model', 'demo'));
        $recorder->emit(new TokenChunk(1, 'stored anyway'));

        self::assertCount(2, $store->events('run_test4'));
    }

    private function context(): RunContext
    {
        return new RunContext(fn (): int => $this->now);
    }
}
