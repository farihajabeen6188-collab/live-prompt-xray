<?php

declare(strict_types=1);

namespace XRay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\InMemory\Store;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\RetrieverInterface;
use XRay\Event\RunStarted;
use XRay\Instrumentation\TracingRetriever;
use XRay\Recorder\Recorder;
use XRay\Recorder\Redactor;
use XRay\Recorder\RunContext;
use XRay\Storage\SqliteTraceStore;
use XRay\Tests\Support\CollectingPublisher;

final class TracingRetrieverTest extends TestCase
{
    public function testRetrievalsAreRecordedWithScoresFromARealStore(): void
    {
        $store = new Store();
        $store->add([
            new VectorDocument('refunds', new Vector([1.0, 0.0]), new Metadata(['_text' => 'Annual plans are refundable within 30 days.', '_source' => 'refunds.md'])),
            new VectorDocument('pricing', new Vector([0.0, 1.0]), new Metadata(['_text' => 'Pricing starts at 9 EUR.', '_source' => 'pricing.md'])),
        ]);
        $retriever = new class($store) implements RetrieverInterface {
            public function __construct(private readonly Store $store)
            {
            }

            public function retrieve(string $query, array $options = []): iterable
            {
                return $this->store->query(new VectorQuery(new Vector([0.9, 0.1])), ['maxItems' => 1]);
            }
        };

        $publisher = new CollectingPublisher();
        $recorder = new Recorder(new RunContext(), new Redactor(), new SqliteTraceStore(':memory:'), $publisher);
        $recorder->beginRun('run_retrieval1', new RunStarted('refunds?', 'agent', 'model', 'demo'));

        $documents = iterator_to_array((new TracingRetriever($retriever, $recorder, 'docs'))->retrieve('refund policy'), false);

        self::assertCount(1, $documents);
        self::assertSame('retrieval.hit', $publisher->events[1]['type']);
        $data = $publisher->events[1]['data'];
        self::assertSame('docs', $data['store']);
        self::assertSame('refund policy', $data['query']);
        self::assertSame('refunds', $data['hits'][0]['id']);
        self::assertSame('refunds.md', $data['hits'][0]['source']);
        self::assertIsFloat($data['hits'][0]['score']);
        self::assertStringStartsWith('Annual plans', $data['hits'][0]['snippet']);
    }
}
