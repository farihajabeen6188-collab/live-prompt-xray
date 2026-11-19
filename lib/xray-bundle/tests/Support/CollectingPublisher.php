<?php

declare(strict_types=1);

namespace XRay\Tests\Support;

use XRay\Transport\EventPublisher;

final class CollectingPublisher implements EventPublisher
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public bool $fail = false;

    public function publish(string $runId, array $event): bool
    {
        if ($this->fail) {
            throw new \RuntimeException('hub is down');
        }
        $this->events[] = $event;

        return true;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (array $event): string => (string) $event['type'], $this->events);
    }
}
