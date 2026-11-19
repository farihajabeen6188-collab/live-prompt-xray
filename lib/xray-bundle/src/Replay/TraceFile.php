<?php

declare(strict_types=1);

namespace XRay\Replay;

use XRay\Event\Envelope;
use XRay\Storage\RunRecord;

/**
 * The portable trace format: {"format": "xray-trace", "version": 1, "createdAt", "run", "events"}.
 */
final class TraceFile
{
    public const string FORMAT = 'xray-trace';
    public const int VERSION = 1;

    /**
     * @param list<Envelope> $events
     *
     * @return array{format: string, version: int, createdAt: string, run: array<string, mixed>, events: list<array<string, mixed>>}
     */
    public function export(RunRecord $run, array $events): array
    {
        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'createdAt' => new \DateTimeImmutable()->format(\DATE_ATOM),
            'run' => $run->toArray(),
            'events' => array_map(static fn (Envelope $event): array => $event->toArray(), $events),
        ];
    }

    /**
     * Validates a decoded trace file. The imported run gets a fresh id so it never overwrites a recorded one.
     *
     * @param array<mixed> $trace
     *
     * @return array{RunRecord, list<Envelope>}
     *
     * @throws InvalidTraceException
     */
    public function import(array $trace, string $newRunId): array
    {
        if (self::FORMAT !== ($trace['format'] ?? null)) {
            throw new InvalidTraceException('This is not an X-Ray trace file (missing "format": "xray-trace").');
        }
        $version = $trace['version'] ?? null;
        if (!\is_int($version)) {
            throw new InvalidTraceException('The trace file has no version.');
        }
        if (self::VERSION !== $version) {
            throw new InvalidTraceException(\sprintf('Trace format version %d is not supported (this X-Ray reads version %d).', $version, self::VERSION));
        }
        $run = $trace['run'] ?? null;
        $rawEvents = $trace['events'] ?? null;
        if (!\is_array($run) || !\is_array($rawEvents) || [] === $rawEvents) {
            throw new InvalidTraceException('The trace file needs a "run" object and a non-empty "events" list.');
        }

        $events = [];
        foreach ($rawEvents as $i => $raw) {
            if (!\is_array($raw)) {
                throw new InvalidTraceException(\sprintf('Event #%d is not an object.', $i));
            }
            try {
                $event = Envelope::fromArray($raw);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidTraceException(\sprintf('Event #%d: %s', $i, $e->getMessage()), previous: $e);
            }
            $events[] = new Envelope($newRunId, $event->seq, $event->ts, $event->type, $event->data);
        }
        usort($events, static fn (Envelope $a, Envelope $b): int => $a->seq <=> $b->seq);

        $finished = null;
        foreach ($events as $event) {
            if ('run.finished' === $event->type) {
                $finished = $event;
            }
        }

        try {
            $startedAt = new \DateTimeImmutable(\is_string($run['startedAt'] ?? null) ? $run['startedAt'] : 'now');
        } catch (\Exception) {
            $startedAt = new \DateTimeImmutable();
        }

        $record = new RunRecord(
            id: $newRunId,
            prompt: self::string($run, 'prompt'),
            agent: self::string($run, 'agent', 'imported'),
            model: self::string($run, 'model', 'unknown'),
            mode: self::string($run, 'mode', 'demo'),
            scenario: \is_string($run['scenario'] ?? null) ? $run['scenario'] : null,
            startedAt: $startedAt,
            status: null === $finished ? 'failed' : (true === ($finished->data['ok'] ?? true) ? 'finished' : 'failed'),
            summary: $finished->data ?? [],
            imported: true,
        );

        return [$record, $events];
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key, string $default = ''): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : $default;
    }
}
