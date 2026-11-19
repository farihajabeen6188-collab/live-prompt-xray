<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Demo Mode's model: a real PlatformInterface whose answers come from rules instead of a neural network:
 * the five showcase prompts follow their JSON scripts (scripted/*.json), any other prompt goes to the
 * DemoPlanner. It streams tokens with realistic pacing and requests tool calls;
 * everything else in the run (agent, toolbox, MCP server, store) is real.
 *
 * It reports no token usage: X-Ray estimates tokens for it and labels them as estimates.
 */
final readonly class ScriptedPlatform implements PlatformInterface
{
    public const string MODEL = 'scripted-demo';

    public function __construct(
        private ScriptBook $scripts,
        private DemoPlanner $planner,
        private int $pacingMicroseconds = 35_000,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $messages = $input instanceof MessageBag ? $input : new MessageBag();
        // Only what happened since the latest user message belongs to this request (chat history comes before it).
        $all = $messages->getMessages();
        $start = 0;
        foreach ($all as $index => $message) {
            if ($message instanceof UserMessage) {
                $start = $index;
            }
        }
        $current = \array_slice($all, $start);
        $prompt = $all[$start] instanceof UserMessage ? (string) $all[$start]->asText() : '';
        $round = \count(array_filter($current, static fn (MessageInterface $m): bool => $m instanceof AssistantMessage));
        $results = self::toolResults($current);

        $turn = $this->scripts->isScenarioPrompt($prompt)
            ? $this->scripts->turnFor($prompt, $round, $results)
            : $this->planner->turn($prompt, $round, $results, self::injectedPassages($messages));
        $toolCalls = $this->toolCalls($turn, self::toolNames($options), \count($all));

        return new DeferredResult(new PassThroughConverter(), new InMemoryRawResult(object: new StreamResult($this->stream($turn->text, $toolCalls))), $options);
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return new FallbackModelCatalog();
    }

    /**
     * @param list<ToolCall> $toolCalls
     *
     * @return \Generator<TextDelta|ToolCallComplete>
     */
    private function stream(string $text, array $toolCalls): \Generator
    {
        usleep($this->pacingMicroseconds * 8); // time to first token
        preg_match_all('/\S+\s*/u', $text, $words);
        foreach (array_chunk($words[0], 2) as $i => $chunk) {
            usleep($this->pacingMicroseconds + (crc32(implode('', $chunk)) % 3) * (int) ($this->pacingMicroseconds / 2));
            yield new TextDelta(implode('', $chunk));
        }
        if ([] !== $toolCalls) {
            usleep($this->pacingMicroseconds * 3);
            yield new ToolCallComplete($toolCalls);
        }
    }

    /**
     * @param list<string> $available
     *
     * @return list<ToolCall>
     */
    private function toolCalls(ScriptedTurn $turn, array $available, int $messageCount): array
    {
        $calls = [];
        foreach ($turn->calls as $i => $call) {
            $name = $call['tool'];
            foreach ($available as $candidate) {
                if ($candidate === $name || str_ends_with($candidate, '_'.$name)) {
                    $name = $candidate;
                    break;
                }
            }
            $calls[] = new ToolCall(\sprintf('call_%d_%d', $messageCount, $i + 1), $name, $call['args']);
        }

        return $calls;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private static function toolNames(array $options): array
    {
        $tools = \is_array($options['tools'] ?? null) ? $options['tools'] : [];

        return array_values(array_map(static fn (Tool $tool): string => $tool->getName(), array_filter($tools, static fn (mixed $tool): bool => $tool instanceof Tool)));
    }

    /**
     * The latest result of each tool, decoded from JSON when possible.
     *
     * @param list<MessageInterface> $messages
     *
     * @return array<string, mixed>
     */
    private static function toolResults(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolCallMessage) {
                $text = (string) $message->asText();
                $decoded = json_decode($text, true);
                $results[$message->getToolCall()->getName()] = null === $decoded ? $text : $decoded;
            }
        }

        return $results;
    }

    /**
     * Passages a retrieval processor put into the system prompt, as "[doc-id] text" lines.
     *
     * @return list<array{id: string, text: string}>
     */
    private static function injectedPassages(MessageBag $messages): array
    {
        $system = (string) $messages->getSystemMessage()?->getContent();
        preg_match_all('/^\[([\w.-]+)\]\s+(.+)$/m', $system, $matches, \PREG_SET_ORDER);

        return array_map(static fn (array $m): array => ['id' => $m[1], 'text' => $m[2]], $matches);
    }
}
