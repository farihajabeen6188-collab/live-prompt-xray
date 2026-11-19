<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use XRay\Recorder\RunContext;
use XRay\Storage\TraceStore;

final class PreviewAndChatTest extends WebTestCase
{
    public function testThePreviewShowsWhatTheAgentWillSeeAndDoWithoutRecordingARun(): void
    {
        $client = static::createClient();
        $store = static::getContainer()->get(TraceStore::class);
        $before = \count($store->runs(200));

        $client->request('POST', '/_xray/preview', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'prompt' => 'What is the refund policy for annual plans, and what is 18% of 240?',
            'history' => [['role' => 'user', 'content' => 'hi'], ['role' => 'assistant', 'content' => 'Hello!']],
        ]));

        self::assertResponseIsSuccessful();
        $preview = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($preview['error']);
        self::assertSame('demo', $preview['agent']);

        // The exact context: the real system prompt processor and the real RAG processor ran.
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($preview['messages'], 'role'));
        self::assertStringContainsString('[03-refunds]', $preview['messages'][0]['content']);
        $labels = array_column($preview['segments'], 'label');
        self::assertContains('SystemPrompt', $labels);
        self::assertContains('DocsContext', $labels);
        self::assertContains('conversation (2 messages)', $labels);
        self::assertSame(array_sum(array_column($preview['segments'], 'tokens')), $preview['totalTokens']);

        // Retrieval and MCP traffic were captured by the usual decorators.
        self::assertSame('03-refunds', $preview['retrievals'][0]['hits'][0]['id']);
        self::assertContains('tools/list', array_column($preview['mcp'], 'method'));

        // Demo Mode: the plan is exact.
        self::assertSame(['demo_calculate', 'search_docs'], array_column($preview['plan']['calls'], 'tool'));
        self::assertSame(['expression' => '240 * 0.18'], $preview['plan']['calls'][0]['args']);
        $planned = array_values(array_filter($preview['tools'], static fn (array $t): bool => $t['planned']));
        self::assertEqualsCanonicalizing(['demo', null], array_column($planned, 'server'), 'One planned tool via MCP, one local.');

        self::assertCount($before, $store->runs(200), 'A preview is never stored as a run.');
    }

    public function testAnEmptyPromptIsRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/_xray/preview', server: ['CONTENT_TYPE' => 'application/json'], content: '{"prompt":"  "}');

        self::assertResponseStatusCodeSame(400);
    }

    public function testAChatTurnSendsTheConversationAndRecordsTheLatestQuestion(): void
    {
        $client = static::createClient();
        $runId = RunContext::newRunId();

        $client->request('POST', '/demo/run', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'prompt' => 'and what is 15% tip on 86?',
            'runId' => $runId,
            'history' => [['role' => 'user', 'content' => 'Will it rain in Lahore today?'], ['role' => 'assistant', 'content' => 'Showers, take an umbrella.']],
        ]));

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('86 * 0.15 = 12.9', $body['text']);

        $events = static::getContainer()->get(TraceStore::class)->events($runId);
        self::assertSame('and what is 15% tip on 86?', $events[0]->data['prompt']);
        $prompt = array_values(array_filter($events, static fn ($e): bool => 'prompt.sent' === $e->type))[0];
        self::assertSame(['system', 'user', 'assistant', 'user'], array_column($prompt->data['messages'], 'role'));
    }
}
