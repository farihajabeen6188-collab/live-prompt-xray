<?php

declare(strict_types=1);

namespace App\Platform;

final readonly class ScriptedTurn
{
    /**
     * @param list<array{tool: string, args: array<string, mixed>}> $calls
     */
    public function __construct(
        public string $text,
        public array $calls = [],
    ) {
    }
}
