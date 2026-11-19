<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ModelConnectionTest extends WebTestCase
{
    protected function tearDown(): void
    {
        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/xray/model.test.json';
        if (is_file($file)) {
            unlink($file);
        }
        parent::tearDown();
    }

    public function testAConnectionIsSavedWithoutEverReturningTheKeyAndCanBeRemoved(): void
    {
        $client = static::createClient();

        $client->request('GET', '/demo/model');
        $initial = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($initial['connected']);
        self::assertContains('openai', array_column($initial['presets'], 'id'));
        self::assertContains('ollama', array_column($initial['presets'], 'id'));

        $client->request('POST', '/demo/model', server: ['CONTENT_TYPE' => 'application/json'], content: '{"provider":"openai","baseUrl":"https://api.openai.com/v1","apiKey":"sk-test-1234567890abcd","model":"gpt-4o-mini"}');
        self::assertResponseIsSuccessful();
        $saved = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('sk-test-1234567890abcd', $saved);
        self::assertSame('••••abcd', json_decode($saved, true, 512, \JSON_THROW_ON_ERROR)['connection']['keyHint']);

        $client->request('GET', '/demo/run');
        $run = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(['live', 'gpt-4o-mini'], [$run['mode'], $run['model']]);

        // Changing only the model keeps the stored key.
        $client->request('POST', '/demo/model', server: ['CONTENT_TYPE' => 'application/json'], content: '{"provider":"openai","baseUrl":"https://api.openai.com/v1","apiKey":"","model":"gpt-4.1-mini"}');
        self::assertSame('••••abcd', json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['connection']['keyHint']);

        $client->request('DELETE', '/demo/model');
        $client->request('GET', '/demo/run');
        self::assertSame('demo', json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['mode']);
    }

    public function testInvalidConnectionsAreRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/demo/model', server: ['CONTENT_TYPE' => 'application/json'], content: '{"baseUrl":"file:///etc/passwd","model":"x"}');
        self::assertResponseStatusCodeSame(400);

        $client->request('POST', '/demo/model/test', server: ['CONTENT_TYPE' => 'application/json'], content: '{"baseUrl":"not a url"}');
        self::assertResponseStatusCodeSame(400);
    }
}
