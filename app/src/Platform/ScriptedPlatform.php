<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
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
 * Demo Mode's model: a real PlatformInterface whose answers come from JSON scripts (scripted/*.json)
 * instead of a neural network. It streams tokens with realistic pacing and requests tool calls;
 * everything else in the run (agent, toolbox, MCP server, store) is real.
 *
 * It reports no token usage: X-Ray estimates tokens for it and labels them as estimates.
 */
final readonly class ScriptedPlatform implements PlatformInterface
{
    public const string MODEL = 'scripted-demo';

    public function __construct(
        private ScriptBook $scripts,
        private int $pacingMicroseconds = 35_000,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        $messages = $input instanceof MessageBag ? $input : new MessageBag();
        $turn = $this->scripts->turnFor(
            (string) $messages->getUserMessage()?->asText(),
            \count(array_filter($messages->getMessages(), static fn ($m): bool => $m instanceof AssistantMessage)),
            self::toolResults($messages),
        );
        $toolCalls = $this->toolCalls($turn, self::toolNames($options), \count($messages->getMessages()));

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
     * @return array<string, mixed>
     */
    private static function toolResults(MessageBag $messages): array
    {
        $results = [];
        foreach ($messages->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                $text = (string) $message->asText();
                $decoded = json_decode($text, true);
                $results[$message->getToolCall()->getName()] = null === $decoded ? $text : $decoded;
            }
        }

        return $results;
    }
}
