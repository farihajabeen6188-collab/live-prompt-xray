<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UI smoke test: the console page, its modules and the demo endpoints it relies on.
 */
final class ConsoleSmokeTest extends WebTestCase
{
    public function testTheConsoleRendersWithEveryModuleReachable(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/_xray');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="xray-console"]');
        foreach (['graph', 'tokens', 'inspector', 'waterfall', 'meters', 'scrub', 'runsList'] as $target) {
            self::assertSelectorExists(\sprintf('[data-xray-console-target="%s"]', $target));
        }
        self::assertSelectorTextContains('.brand', 'X-Ray');
        self::assertSelectorExists('textarea[data-xray-console-target="prompt"]');

        $config = json_decode((string) $crawler->filter('[data-controller="xray-console"]')->attr('data-xray-console-config-value'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('/demo/run', $config['runUrl']);
        self::assertSame('xray/runs/', $config['topicPrefix']);

        $importMap = json_decode($crawler->filter('script[type="importmap"]')->text(), true, 512, \JSON_THROW_ON_ERROR);
        $modules = array_keys($importMap['imports']);
        foreach (['console.js', 'controllers/console_controller.js', 'lib/trace.js', 'lib/graph.js', 'lib/views.js', 'lib/player.js', 'lib/diff.js', 'vendor/stimulus.js'] as $module) {
            self::assertContains('/assets/xray/'.$module, $modules);
        }
        foreach ($importMap['imports'] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        $client->request('GET', (string) $crawler->filter('link[rel="stylesheet"]')->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testTheDemoRunEndpointListsScenariosAndValidatesInput(): void
    {
        $client = static::createClient();

        $client->request('GET', '/demo/run');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('demo', $body['mode']);
        self::assertSame(['umbrella', 'refunds', 'friday', 'slow-flaky', 'pricing'], array_column($body['scenarios'], 'id'));

        $client->request('POST', '/demo/run', server: ['CONTENT_TYPE' => 'application/json'], content: '{"prompt":"","runId":"run_bad"}');
        self::assertResponseStatusCodeSame(400);
    }

    public function testTheHomePageRedirectsToTheConsole(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/_xray');
    }
}
