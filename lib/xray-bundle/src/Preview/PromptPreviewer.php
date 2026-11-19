<?php

declare(strict_types=1);

namespace XRay\Preview;

use Symfony\AI\Agent\AgentAwareInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Tool\Tool;
use XRay\Instrumentation\Mcp\McpToolDirectory;
use XRay\Instrumentation\MessageDescriber;
use XRay\Pricing\CostEstimator;
use XRay\Recorder\Recorder;

/**
 * The pre-flight X-Ray, computed while the user types: runs the agent's real input processors
 * (system prompt, memory, RAG…) on the draft prompt without calling the model, and reports the
 * exact context the model would receive, what each processor added to it, the retrievals and MCP
 * traffic this caused (captured by the usual decorators) and the tools on offer.
 *
 * Nothing is stored or published: the dry run uses a preview run whose events stay in memory.
 */
final readonly class PromptPreviewer
{
    /**
     * @param array<string, array{agent: AgentInterface, model: string, processors: list<InputProcessorInterface>, toolbox: ?ToolboxInterface}> $agents
     */
    public function __construct(
        private array $agents,
        private Recorder $recorder,
        private McpToolDirectory $mcpTools,
        private int $contextWindow = 128_000,
        private ?ToolPlanProvider $planProvider = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function agentNames(): array
    {
        return array_keys($this->agents);
    }

    /**
     * @param list<array{role: string, content: string}> $history earlier turns of the conversation
     *
     * @return array<string, mixed>
     */
    public function preview(string $prompt, array $history = [], ?string $agentName = null): array
    {
        $agentName ??= array_key_first($this->agents);
        $config = null === $agentName ? null : ($this->agents[$agentName] ?? null);
        if (null === $config) {
            throw new \InvalidArgumentException(\sprintf('Unknown agent "%s".', (string) $agentName));
        }

        $context = $this->recorder->context();
        if (null !== $context->current()) {
            throw new \LogicException('A run is being recorded in this process; previews run in their own request.');
        }

        $describer = new MessageDescriber($this->recorder->redactor());
        $run = $context->beginPreview($config['model']);
        $startedAt = $context->now();
        $segments = [];
        $error = null;
        $messages = [];
        $tools = [];
        $input = null;
        $plan = null;
        try {
            $bag = new MessageBag(...[...array_map(self::historyMessage(...), $history), Message::ofUser($prompt)]);
            $segments[] = ['label' => 'your message', 'kind' => 'user', 'tokens' => CostEstimator::estimateTokens($prompt)];
            $historyTokens = array_sum(array_map(static fn (array $m): int => CostEstimator::estimateTokens($m['content']), $history));
            if ($historyTokens > 0) {
                $segments[] = ['label' => \sprintf('conversation (%d messages)', \count($history)), 'kind' => 'history', 'tokens' => $historyTokens];
            }

            $input = new Input($config['model'], $bag, []);
            foreach ($config['processors'] as $processor) {
                if ($processor instanceof AgentAwareInterface) {
                    $processor->setAgent($config['agent']);
                }
                $before = self::tokens($input->getMessageBag());
                $processor->processInput($input);
                $added = self::tokens($input->getMessageBag()) - $before;
                if (0 !== $added) {
                    $segments[] = ['label' => self::shortName($processor::class), 'kind' => 'processor', 'tokens' => $added];
                }
            }
            $messages = array_map($describer->describe(...), $input->getMessageBag()->getMessages());

            foreach ($config['toolbox']?->getTools() ?? [] as $tool) {
                $tools[] = $this->tool($tool, $prompt);
            }
            $plan = $this->planProvider?->plan($prompt, $history);
            if (null !== $plan) {
                foreach ($plan as $index => $call) {
                    foreach ($tools as $t => $tool) {
                        if ($tool['name'] === $call['tool'] || str_ends_with($tool['name'], '_'.$call['tool'])) {
                            $tools[$t]['planned'] = true;
                            $tools[$t]['score'] = 1.0;
                            $plan[$index]['tool'] = $tool['name'];
                        }
                    }
                }
            }
            usort($tools, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $toolTokens = array_sum(array_column($tools, 'tokens'));
            if ($toolTokens > 0) {
                $segments[] = ['label' => \sprintf('tool definitions (%d)', \count($tools)), 'kind' => 'tools', 'tokens' => $toolTokens];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            $context->end();
        }

        $events = $run->captured;
        $total = array_sum(array_column($segments, 'tokens'));

        return [
            'agent' => $agentName,
            'model' => $input?->getModel() ?? $config['model'],
            'durationMs' => intdiv(max(0, $context->now() - $startedAt), 1_000_000),
            'error' => $error,
            'messages' => $messages,
            'segments' => $segments,
            'totalTokens' => $total,
            'contextWindow' => $this->contextWindow,
            'tools' => $tools,
            'plan' => null === $plan ? null : ['source' => $this->planProvider?->source(), 'calls' => $plan],
            'retrievals' => array_values(array_map(static fn (array $e): array => $e['data'], array_filter($events, static fn (array $e): bool => 'retrieval.hit' === $e['type']))),
            'mcp' => array_values(array_map(static fn (array $e): array => ['method' => $e['data']['method'] ?? null, 'direction' => $e['data']['direction'] ?? null, 'server' => $e['data']['server'] ?? null, 'latencyMs' => $e['data']['latencyMs'] ?? null], array_filter($events, static fn (array $e): bool => 'mcp.frame' === $e['type']))),
        ];
    }

    /**
     * @return array{name: string, description: string, server: ?string, score: float, tokens: int, matched: list<string>, planned: bool}
     */
    private function tool(Tool $tool, string $prompt): array
    {
        $words = self::words($prompt);
        $nameWords = self::words(str_replace('_', ' ', $tool->getName()));
        $descriptionWords = self::words($tool->getDescription());
        $matched = [];
        $score = 0.0;
        foreach (array_unique($words) as $word) {
            if (\in_array($word, $nameWords, true)) {
                $score += 3.0;
                $matched[] = $word;
            } elseif (\in_array($word, $descriptionWords, true)) {
                $score += 1.0;
                $matched[] = $word;
            }
        }

        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'server' => $this->mcpTools->serverOf($tool->getName()),
            'score' => round(min(1.0, $score / 4.0), 2),
            'tokens' => CostEstimator::estimateTokens($tool->getName().' '.$tool->getDescription().' '.json_encode($tool->getParameters() ?? [])),
            'matched' => array_values(array_unique($matched)),
            'planned' => false,
        ];
    }

    /**
     * @return list<string> lower-cased words of 3+ letters, crudely singularised
     */
    private static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
            if (mb_strlen($word) >= 3 && !\in_array($word, ['the', 'and', 'for', 'you', 'are', 'with', 'what', 'about', 'how', 'can', 'need', 'our', 'your', 'this', 'that'], true)) {
                $words[] = (string) preg_replace('/(ies|es|s)$/u', '', $word);
            }
        }

        return $words;
    }

    private static function tokens(MessageBag $bag): int
    {
        return array_sum(array_map(static function (MessageInterface $message): int {
            $content = $message->getContent();

            $text = match (true) {
                \is_string($content) => $content,
                $content instanceof \Stringable => (string) $content,
                method_exists($message, 'asText') => (string) $message->asText(),
                default => (string) json_encode($content),
            };

            return CostEstimator::estimateTokens($text);
        }, $bag->getMessages()));
    }

    /**
     * @param array{role: string, content: string} $message
     */
    private static function historyMessage(array $message): MessageInterface
    {
        return 'assistant' === $message['role'] ? Message::ofAssistant($message['content']) : Message::ofUser($message['content']);
    }

    private static function shortName(string $class): string
    {
        $short = substr((string) strrchr('\\'.$class, '\\'), 1);

        return (string) preg_replace('/(Input)?Processor$/', '', $short);
    }
}
