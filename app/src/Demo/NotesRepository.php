<?php

declare(strict_types=1);

namespace App\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The user's notes for the demo, read from data/notes.json.
 */
final readonly class NotesRepository
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/data/notes.json')]
        private string $path,
    ) {
    }

    /**
     * @return list<array{title: string, text: string}>
     */
    public function search(string $query): array
    {
        $words = array_filter(preg_split('/\W+/u', strtolower($query)) ?: [], static fn (string $w): bool => mb_strlen($w) > 2);
        $hits = [];
        foreach ($this->all() as $note) {
            $haystack = strtolower($note['title'].' '.$note['text']);
            $score = \count(array_filter($words, static fn (string $w): bool => str_contains($haystack, $w)));
            if ($score > 0) {
                $hits[] = [$score, $note];
            }
        }
        usort($hits, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $hit): array => $hit[1], \array_slice($hits, 0, 3));
    }

    /**
     * @return list<array{title: string, text: string}>
     */
    private function all(): array
    {
        /** @var list<array{title: string, text: string}> $notes */
        $notes = json_decode((string) file_get_contents($this->path), true, 512, \JSON_THROW_ON_ERROR);

        return $notes;
    }
}
