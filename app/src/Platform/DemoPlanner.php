<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The scripted model's "brain" for free prompts: reads the request, plans tool calls (several at once
 * when the prompt asks for several things), reacts to the real results (retries a failure, follows up
 * on what the notes say) and writes the answer from those results.
 *
 * It is rules, not a neural network, and the console says so. What it makes visible is real: the
 * tool calls run against the real toolbox and MCP server, and every answer quotes real results.
 */
final class DemoPlanner
{
    private const array CITIES = ['Paris', 'Berlin', 'London', 'Tokyo', 'New York', 'Madrid', 'Rome', 'Amsterdam', 'Lisbon', 'Vienna', 'Zurich', 'Dubai', 'Lahore', 'Karachi', 'Islamabad', 'Delhi', 'Mumbai', 'Istanbul', 'Cairo', 'Sydney', 'Toronto', 'Chicago', 'San Francisco', 'Singapore', 'Seoul', 'Lyon', 'Munich', 'Brussels', 'Dublin', 'Oslo'];
    private const array DAYS = ['today', 'tomorrow', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'weekend'];
    private const string DOCS_TOPICS = '/\b(nimbus|pricing|price|prices|plan|plans|refund|refunds|sla|uptime|security|encrypt\w*|region|regions|support|limit|limits|discount|discounts|billing|bandwidth|storage|student|non-?profit|workers?)\b/i';

    /**
     * @param array<string, mixed>                  $results latest result per tool name (prefixed names allowed)
     * @param list<array{id: string, text: string}> $context passages injected into the prompt by retrieval
     */
    public function turn(string $prompt, int $round, array $results, array $context = []): ScriptedTurn
    {
        $intents = $this->intents($prompt);

        if (0 === $round) {
            $calls = $this->firstCalls($intents);

            return [] === $calls ? new ScriptedTurn($this->answer($prompt, $intents, $results, $context)) : new ScriptedTurn($this->announce($intents), $calls);
        }

        $followUps = $this->followUps($intents, $results);
        if ([] !== $followUps['calls'] && $round < 3) {
            return new ScriptedTurn($followUps['say'], $followUps['calls']);
        }

        return new ScriptedTurn($this->answer($prompt, $intents, $results, $context));
    }

    /**
     * The calls the scripted model will make first: what the typing preview shows as "plan".
     *
     * @return list<array{tool: string, args: array<string, mixed>, reason: string}>
     */
    public function plan(string $prompt): array
    {
        return $this->firstCalls($this->intents($prompt));
    }

    /**
     * @return array{weather: ?array{city: ?string, day: string}, math: ?string, notes: ?string, docs: ?string, lookup: ?string, service: ?int, greeting: bool}
     */
    private function intents(string $prompt): array
    {
        $p = ' '.$prompt.' ';
        $weather = null;
        if (preg_match('/weather|rain|umbrella|forecast|temperature|sunny|snow|cold|hot|warm|jacket|coat/i', $p)) {
            $day = 'tomorrow';
            foreach (self::DAYS as $candidate) {
                if (preg_match('/\b'.$candidate.'\b/i', $p)) {
                    $day = $candidate;
                    break;
                }
            }
            $weather = ['city' => $this->city($p), 'day' => $day];
        }

        $math = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*%\s*(?:tip\s+)?(?:of|on)\s+(?:[^\d]{0,8})?(\d+(?:\.\d+)?)/i', $p, $m)) {
            $math = \sprintf('%s * %s', $m[2], rtrim(rtrim(number_format((float) $m[1] / 100, 4, '.', ''), '0'), '.'));
        } elseif (preg_match('/(-?\d+(?:\.\d+)?(?:\s*(?:[-+*\/^x×]|plus|minus|times|divided by)\s*\(?-?\d+(?:\.\d+)?\)?)+)/i', $p, $m)) {
            $math = trim((string) preg_replace(['/\bplus\b/i', '/\bminus\b/i', '/\btimes\b|×|(?<=\d)\s*x\s*(?=\d)/i', '/\bdivided by\b/i'], ['+', '-', '*', '/'], $m[1]));
        }

        $notes = null;
        if (preg_match('/\b(?:my\s+)?notes?\b(?:\s+(?:about|on|for)\s+(?:the\s+|my\s+)?([\w\s-]{2,30}?))?(?=[,.?!]|\s+and\b|\s*$)/i', $p, $m)) {
            $notes = trim($m[1] ?? '') ?: $this->keywords($prompt);
        } elseif (preg_match('/\b(offsite|groceries|shopping list|remind me|todo)\b/i', $p, $m)) {
            $notes = strtolower($m[1]);
        }

        $docs = preg_match(self::DOCS_TOPICS, $p) ? $this->keywords($prompt) : null;
        $lookup = null;
        if (preg_match('/\b(q[1-4])\s+report\b/i', $p, $m)) {
            $lookup = strtoupper($m[1]).' report';
        } elseif (preg_match('/\b(?:report|archive|history|look\s?up)\b(?:.*?\b(?:for|about|on|of)\s+(?:the\s+)?([\w\s-]{2,30}?))?(?=[,.?!]|\s+(?:and|then)\b|\s*$)/i', $p, $m)) {
            $lookup = trim($m[1] ?? '') ?: 'latest report';
        }
        $service = preg_match('/service\s*#?\s*(\d+)/i', $p, $m) ? (int) $m[1] : (preg_match('/\b(status|health|is .* (down|up))\b/i', $p) ? 42 : null);

        return [
            'weather' => $weather,
            'math' => $math,
            'notes' => $notes,
            'docs' => $docs,
            'lookup' => $lookup,
            'service' => $service,
            'greeting' => 1 === preg_match('/^\s*(hi|hello|hey|salam|good (morning|evening))\b/i', $prompt),
        ];
    }

    /**
     * @param array{weather: ?array{city: ?string, day: string}, math: ?string, notes: ?string, docs: ?string, lookup: ?string, service: ?int, greeting: bool} $i
     *
     * @return list<array{tool: string, args: array<string, mixed>, reason: string}>
     */
    private function firstCalls(array $i): array
    {
        $calls = [];
        if (null !== $i['weather']) {
            $calls[] = null === $i['weather']['city']
                ? ['tool' => 'search_notes', 'args' => ['query' => 'home city'], 'reason' => 'no city given: find the home city in the notes']
                : ['tool' => 'get_weather', 'args' => ['city' => $i['weather']['city'], 'day' => $i['weather']['day']], 'reason' => 'asks about the weather in '.$i['weather']['city']];
        }
        if (null !== $i['notes'] && (null === $i['weather'] || null !== $i['weather']['city'])) {
            $calls[] = ['tool' => 'search_notes', 'args' => ['query' => $i['notes']], 'reason' => 'mentions the notes'];
        }
        if (null !== $i['math']) {
            $calls[] = ['tool' => 'calculate', 'args' => ['expression' => $i['math']], 'reason' => 'contains a calculation'];
        }
        if (null !== $i['lookup']) {
            $calls[] = ['tool' => 'slow_lookup', 'args' => ['topic' => $i['lookup']], 'reason' => 'asks to look up "'.$i['lookup'].'"'];
        }
        if (null !== $i['service']) {
            $calls[] = ['tool' => 'flaky_service', 'args' => ['id' => $i['service']], 'reason' => 'asks to check service '.$i['service']];
        }
        if (null !== $i['docs'] && [] !== $calls) {
            $calls[] = ['tool' => 'search_docs', 'args' => ['query' => $i['docs']], 'reason' => 'also asks about the Nimbus docs'];
        }

        return $calls;
    }

    /**
     * @param array{weather: ?array{city: ?string, day: string}, math: ?string, notes: ?string, docs: ?string, lookup: ?string, service: ?int, greeting: bool} $i
     * @param array<string, mixed>                                                                                                                             $results
     *
     * @return array{say: string, calls: list<array{tool: string, args: array<string, mixed>}>}
     */
    private function followUps(array $i, array $results): array
    {
        $say = [];
        $calls = [];
        if (null !== $i['service'] && self::failed(self::lookup($results, 'flaky_service'))) {
            $say[] = \sprintf('Service %d answered with an error, retrying once.', $i['service']);
            $calls[] = ['tool' => 'flaky_service', 'args' => ['id' => $i['service']]];
        }
        if (null !== $i['weather'] && null === $i['weather']['city'] && null === self::lookup($results, 'get_weather')) {
            $notes = json_encode(self::lookup($results, 'search_notes')) ?: '';
            $city = $this->city($notes) ?? 'Paris';
            $say[] = \sprintf('Your notes say %s, checking the forecast there.', $city);
            $calls[] = ['tool' => 'get_weather', 'args' => ['city' => $city, 'day' => $i['weather']['day']]];
        }

        return ['say' => implode(' ', $say), 'calls' => $calls];
    }

    /**
     * @param array{weather: ?array{city: ?string, day: string}, math: ?string, notes: ?string, docs: ?string, lookup: ?string, service: ?int, greeting: bool} $i
     */
    private function announce(array $i): string
    {
        $parts = [];
        if (null !== $i['weather']) {
            $parts[] = null === $i['weather']['city'] ? 'find your city in your notes' : 'check the weather in '.$i['weather']['city'];
        }
        if (null !== $i['notes'] && (null === $i['weather'] || null !== $i['weather']['city'])) {
            $parts[] = 'search your notes';
        }
        if (null !== $i['math']) {
            $parts[] = 'calculate '.$i['math'];
        }
        if (null !== $i['lookup']) {
            $parts[] = 'look up '.$i['lookup'].' in the archive';
        }
        if (null !== $i['service']) {
            $parts[] = 'check service '.$i['service'];
        }
        if (null !== $i['docs'] && [] !== $parts) {
            $parts[] = 'search the docs';
        }
        $last = array_pop($parts);

        return 'I\'ll '.([] === $parts ? $last : implode(', ', $parts).' and '.$last).'.';
    }

    /**
     * @param array{weather: ?array{city: ?string, day: string}, math: ?string, notes: ?string, docs: ?string, lookup: ?string, service: ?int, greeting: bool} $i
     * @param array<string, mixed>                                                                                                                             $results
     * @param list<array{id: string, text: string}>                                                                                                            $context
     */
    private function answer(string $prompt, array $i, array $results, array $context): string
    {
        $out = [];
        $weather = self::lookup($results, 'get_weather');
        if (\is_array($weather)) {
            $rain = (int) ($weather['chanceOfRainPercent'] ?? 0);
            $out[] = \sprintf('%s %s: %s, about %d°C, %d%% chance of rain. %s', $weather['city'] ?? '?', $weather['day'] ?? '', $weather['condition'] ?? '?', (int) ($weather['temperatureC'] ?? 0), $rain,
                preg_match('/umbrella|rain/i', $prompt) ? ($rain >= 50 ? 'Take an umbrella.' : 'No umbrella needed.') : ((int) ($weather['temperatureC'] ?? 0) < 12 ? 'Bring a jacket.' : 'Pleasant enough.'));
        }
        $notes = self::lookup($results, 'search_notes');
        if (null !== $i['notes'] && \is_array($notes)) {
            $first = $notes[0] ?? null;
            $out[] = \is_array($first) ? \sprintf('From your notes, "%s": %s', $first['title'] ?? '', $first['text'] ?? '') : 'Nothing in your notes matches that.';
        }
        $calc = self::lookup($results, 'calculate');
        if (\is_array($calc)) {
            $out[] = \sprintf('%s = %s.', $calc['expression'] ?? $i['math'], (string) ($calc['result'] ?? '?'));
        } elseif (\is_string($calc)) {
            $out[] = 'The calculator refused: '.$calc;
        }
        $archive = self::lookup($results, 'slow_lookup');
        if (\is_array($archive)) {
            $out[] = (string) ($archive['summary'] ?? '');
        }
        $service = self::lookup($results, 'flaky_service');
        if (\is_array($service)) {
            $out[] = \sprintf('Service %d is %s (attempt %d).', (int) ($service['id'] ?? 0), $service['status'] ?? '?', (int) ($service['attempt'] ?? 1));
        } elseif (null !== $i['service'] && self::failed($service)) {
            $out[] = \sprintf('Service %d is still failing: %s', $i['service'], (string) $service);
        }
        $docs = self::lookup($results, 'search_docs');
        if (\is_array($docs) && \is_array($docs[0] ?? null)) {
            $out[] = \sprintf('%s [%s]', self::firstSentences((string) ($docs[0]['text'] ?? ''), 2), $docs[0]['id'] ?? '');
        }
        if (null !== $i['docs'] && [] !== $context && !\is_array($docs)) {
            $wide = 1 === preg_match('/summar|everything|overview|all\b|compare/i', $prompt);
            foreach (\array_slice($context, 0, $wide ? 2 : 1) as $passage) {
                $out[] = \sprintf('%s [%s]', self::firstSentences($passage['text'], $wide ? 4 : 3), $passage['id']);
            }
        }

        if ([] !== $out) {
            return implode(' ', array_filter($out));
        }
        if ($i['greeting']) {
            return 'Hello! I\'m the scripted demo model. Ask me about the weather somewhere, your notes, a calculation like "18% of 240", the Q4 report, service 42, or Nimbus pricing and refunds, or several at once.';
        }

        return 'I\'m the scripted demo model, so I only act on things my tools can do: weather, your notes, calculations, the Q4 archive, service checks and the Nimbus docs. Connect a real model (the model button at the top) to talk about anything.';
    }

    private function city(string $text): ?string
    {
        foreach (self::CITIES as $city) {
            if (preg_match('/\b'.preg_quote($city, '/').'\b/i', $text)) {
                return $city;
            }
        }

        return preg_match('/\b(?:in|at|for)\s+([A-Z][a-z]{2,}(?:\s[A-Z][a-z]+)?)/', $text, $m) && !\in_array(strtolower($m[1]), self::DAYS, true) ? $m[1] : null;
    }

    private function keywords(string $prompt): string
    {
        $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', strtolower($prompt)) ?: [], static fn (string $w): bool => mb_strlen($w) > 3 && !\in_array($w, ['what', 'about', 'everything', 'know', 'tell', 'summarize', 'please', 'could', 'would', 'with', 'from', 'that', 'this', 'there', 'their', 'notes', 'note'], true));

        return implode(' ', \array_slice(array_values($words), 0, 6)) ?: $prompt;
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function lookup(array $results, string $tool): mixed
    {
        $value = null;
        foreach ($results as $name => $result) {
            if ($name === $tool || str_ends_with($name, '_'.$tool)) {
                $value = $result;
            }
        }

        return $value;
    }

    private static function failed(mixed $result): bool
    {
        return \is_string($result) && 1 === preg_match('/error|fail|unavailable|503/i', $result);
    }

    private static function firstSentences(string $text, int $count): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [];

        return implode(' ', \array_slice($sentences, 0, $count));
    }
}
