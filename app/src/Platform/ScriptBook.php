<?php

declare(strict_types=1);

namespace App\Platform;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads the scenario scripts (scripted/*.json) and decides what the scripted model says next.
 *
 * Script format: {id, title, prompt, description, order, match (regex), variants: [{when?, turns: [{say, call?}]}]}.
 * A turn's "say" text may use the real tool results of the run:
 *   {{tool.path}}                       value from the tool's latest result, e.g. {{get_weather.temperatureC}}
 *   {{when tool.path >= 50 | yes | no}} numeric condition
 *   {{failed tool | yes | no}}          whether the tool's latest call failed
 * A call with "if_failed": "tool" is only made when that tool's latest call failed (a retry).
 */
final class ScriptBook
{
    /** @var list<array<string, mixed>>|null */
    private ?array $scripts = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/scripted')]
        private readonly string $directory,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, prompt: string, description: string}>
     */
    public function scenarios(): array
    {
        return array_map(static fn (array $s): array => [
            'id' => (string) $s['id'],
            'title' => (string) $s['title'],
            'prompt' => (string) $s['prompt'],
            'description' => (string) ($s['description'] ?? ''),
        ], array_values(array_filter($this->scripts(), static fn (array $s): bool => '' !== ($s['prompt'] ?? ''))));
    }

    public function scenarioFor(string $prompt): ?string
    {
        $script = $this->scriptFor($prompt);

        return '' === ($script['prompt'] ?? '') ? null : (string) $script['id'];
    }

    /**
     * @param array<string, mixed> $results latest result per tool name
     */
    public function turnFor(string $prompt, int $round, array $results): ScriptedTurn
    {
        $script = $this->scriptFor($prompt);
        $turns = [];
        foreach (\is_array($script['variants'] ?? null) ? $script['variants'] : [] as $variant) {
            $when = \is_string($variant['when'] ?? null) ? $variant['when'] : null;
            if (null === $when || 1 === preg_match('~'.$when.'~iu', $prompt)) {
                $turns = $variant['turns'];
                break;
            }
        }
        $turn = $turns[min($round, \count($turns) - 1)] ?? ['say' => ''];

        $calls = [];
        foreach (\is_array($turn['call'] ?? null) ? $turn['call'] : [] as $call) {
            if (isset($call['if_failed']) && !self::failed(self::lookup($results, (string) $call['if_failed']))) {
                continue;
            }
            $calls[] = ['tool' => (string) $call['tool'], 'args' => \is_array($call['args'] ?? null) ? $call['args'] : []];
        }
        // The last turn of a script is the answer: no more tool calls, whatever the round.
        if ($round >= \count($turns) - 1) {
            $calls = [];
        }

        return new ScriptedTurn($this->render((string) ($turn['say'] ?? ''), $results), $calls);
    }

    /**
     * @param array<string, mixed> $results
     */
    private function render(string $text, array $results): string
    {
        return (string) preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/u', static function (array $m) use ($results): string {
            $expression = $m[1];
            if (preg_match('/^failed\s+(\w+)\s*\|(.*?)\|(.*)$/su', $expression, $f)) {
                return trim(self::failed(self::lookup($results, $f[1])) ? $f[2] : $f[3]);
            }
            if (preg_match('/^when\s+([\w.]+)\s*(>=|<=|>|<|==)\s*(-?[\d.]+)\s*\|(.*?)\|(.*)$/su', $expression, $w)) {
                $value = self::lookup($results, $w[1]);
                $value = is_numeric($value) ? (float) $value : null;
                $limit = (float) $w[3];
                $holds = null !== $value && match ($w[2]) {
                    '>=' => $value >= $limit, '<=' => $value <= $limit, '>' => $value > $limit, '<' => $value < $limit, default => $value === $limit,
                };

                return trim($holds ? $w[4] : $w[5]);
            }
            $value = self::lookup($results, $expression);

            return \is_scalar($value) ? (string) $value : '(unknown)';
        }, $text);
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function lookup(array $results, string $path): mixed
    {
        $segments = explode('.', $path);
        $tool = array_shift($segments);
        $value = null;
        foreach ($results as $name => $result) {
            if ($name === $tool || str_ends_with($name, '_'.$tool)) {
                $value = $result;
            }
        }
        foreach ($segments as $segment) {
            $value = \is_array($value) && \array_key_exists($segment, $value) ? $value[$segment] : null;
        }

        return $value;
    }

    private static function failed(mixed $result): bool
    {
        return \is_string($result) && 1 === preg_match('/error|fail|unavailable|503/i', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function scriptFor(string $prompt): array
    {
        $fallback = [];
        foreach ($this->scripts() as $script) {
            $match = \is_string($script['match'] ?? null) ? $script['match'] : null;
            if (null === $match) {
                $fallback = $script;
            } elseif (1 === preg_match('~'.$match.'~iu', $prompt)) {
                return $script;
            }
        }

        return $fallback;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scripts(): array
    {
        if (null !== $this->scripts) {
            return $this->scripts;
        }
        $scripts = [];
        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            $script = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($script)) {
                /** @var array<string, mixed> $script */
                $scripts[] = $script;
            }
        }
        usort($scripts, static fn (array $a, array $b): int => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        return $this->scripts = $scripts;
    }
}
