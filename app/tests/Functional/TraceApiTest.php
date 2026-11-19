<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use XRay\Event\RunFinished;
use XRay\Event\RunStarted;
use XRay\Event\ToolCallRequested;
use XRay\Recorder\Recorder;
use XRay\Recorder\RunContext;

final class TraceApiTest extends WebTestCase
{
    public function testExportImportAndDiffOverHttp(): void
    {
        $client = static::createClient();
        $a = $this->record('Do I need an umbrella?', 'search_notes');
        $b = $this->record('Do I need an umbrella in Paris?', 'get_weather');

        $client->request('GET', \sprintf('/_xray/runs/%s/export', $a));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Disposition', \sprintf('attachment; filename="xray-%s.json"', $a));
        $exported = (string) $client->getResponse()->getContent();

        $client->request('POST', '/_xray/import', server: ['CONTENT_TYPE' => 'application/json'], content: $exported);
        self::assertResponseStatusCodeSame(201);
        $imported = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['run'];
        self::assertNotSame($a, $imported['id']);
        self::assertTrue($imported['imported']);

        $client->request('GET', \sprintf('/_xray/runs/%s', $imported['id']));
        self::assertResponseIsSuccessful();
        self::assertCount(3, json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['events']);

        $client->request('GET', \sprintf('/_xray/diff?a=%s&b=%s', $a, $b));
        self::assertResponseIsSuccessful();
        $diff = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['diff'];
        self::assertSame(0, $diff['divergence']['index']);

        $client->request('GET', '/_xray/runs');
        $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['runs'], 'id');
        self::assertContains($imported['id'], $ids);
    }

    public function testAnInvalidImportIsRejectedWithAReadableError(): void
    {
        $client = static::createClient();
        $client->request('POST', '/_xray/import', server: ['CONTENT_TYPE' => 'application/json'], content: '{"format":"xray-trace","version":3,"run":{},"events":[{}]}');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('version 3 is not supported', (string) $client->getResponse()->getContent());
    }

    private function record(string $prompt, string $tool): string
    {
        $recorder = static::getContainer()->get(Recorder::class);
        $runId = RunContext::newRunId();
        $recorder->beginRun($runId, new RunStarted($prompt, 'demo', 'scripted', 'demo'));
        $recorder->emit(new ToolCallRequested('call_1', $tool, [], 1));
        $recorder->endRun(new RunFinished(5, 1, 1, 1, 1, null, 'ok', true));

        return $runId;
    }
}
