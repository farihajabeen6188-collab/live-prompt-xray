<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\DemoPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DemoPlannerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<array{string, array<string, mixed>}>}>
     */
    public static function prompts(): iterable
    {
        yield 'weather and tip' => ["What's the weather in Tokyo on Saturday and what is 15% tip on 86?", [['get_weather', ['city' => 'Tokyo', 'day' => 'saturday']], ['calculate', ['expression' => '86 * 0.15']]]];
        yield 'rain and a service' => ['Will it rain in Lahore today? Also check service 7', [['get_weather', ['city' => 'Lahore', 'day' => 'today']], ['flaky_service', ['id' => 7]]]];
        yield 'no city: notes first' => ['Do I need an umbrella?', [['search_notes', ['query' => 'home city']]]];
        yield 'plain arithmetic' => ['compute 1200 * 12 - 300', [['calculate', ['expression' => '1200 * 12 - 300']]]];
        yield 'words for operators' => ['what is 7 times 6', [['calculate', ['expression' => '7 * 6']]]];
        yield 'notes about a topic' => ['what are my notes about groceries', [['search_notes', ['query' => 'groceries']]]];
        yield 'Q4 report' => ['Look up the Q4 report', [['slow_lookup', ['topic' => 'Q4 report']]]];
        yield 'docs only: answered from the injected context' => ['Tell me about Nimbus security', []];
        yield 'small talk' => ['hello there', []];
    }

    /**
     * @param list<array{string, array<string, mixed>}> $expected
     */
    #[DataProvider('prompts')]
    public function testItPlansTheFirstToolCalls(string $prompt, array $expected): void
    {
        $plan = new DemoPlanner()->plan($prompt);

        self::assertSame($expected, array_map(static fn (array $c): array => [$c['tool'], $c['args']], $plan));
    }

    public function testItRetriesAFailedServiceAndAnswersFromTheResults(): void
    {
        $planner = new DemoPlanner();
        $prompt = 'Check service 42';

        $retry = $planner->turn($prompt, 1, ['demo_flaky_service' => 'Tool "flaky_service" returned an error: 503']);
        self::assertSame([['tool' => 'flaky_service', 'args' => ['id' => 42]]], $retry->calls);

        $answer = $planner->turn($prompt, 2, ['demo_flaky_service' => ['id' => 42, 'status' => 'healthy', 'attempt' => 2]]);
        self::assertSame([], $answer->calls);
        self::assertSame('Service 42 is healthy (attempt 2).', $answer->text);
    }

    public function testItFollowsTheNotesToFindTheCity(): void
    {
        $turn = new DemoPlanner()->turn('Do I need an umbrella?', 1, ['demo_search_notes' => [['title' => 'Home city', 'text' => 'I live in Berlin.']]]);

        self::assertSame([['tool' => 'get_weather', 'args' => ['city' => 'Berlin', 'day' => 'tomorrow']]], $turn->calls);
    }

    public function testDocsQuestionsAreAnsweredFromTheInjectedPassagesWithCitations(): void
    {
        $turn = new DemoPlanner()->turn('What is the refund policy?', 0, [], [['id' => '03-refunds', 'text' => 'Annual plans can be refunded within 30 days. After that, pro rata.']]);

        self::assertSame([], $turn->calls);
        self::assertStringContainsString('[03-refunds]', $turn->text);
        self::assertStringContainsString('30 days', $turn->text);
    }
}
