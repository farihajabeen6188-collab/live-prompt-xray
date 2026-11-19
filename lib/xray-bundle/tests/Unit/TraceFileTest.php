<?php

declare(strict_types=1);

namespace XRay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XRay\Event\Envelope;
use XRay\Replay\InvalidTraceException;
use XRay\Replay\TraceFile;
use XRay\Storage\RunRecord;

final class TraceFileTest extends TestCase
{
    public function testAnExportedTraceImportsBackUnderANewId(): void
    {
        $file = new TraceFile();
        $run = new RunRecord('run_original1', 'hello', 'demo', 'scripted', 'demo', 'umbrella', new \DateTimeImmutable('2026-11-19T10:00:00+00:00'), 'finished', ['durationMs' => 9]);
        $events = [
            new Envelope('run_original1', 1, 0, 'run.started', ['prompt' => 'hello']),
            new Envelope('run_original1', 2, 9, 'run.finished', ['durationMs' => 9, 'ok' => true]),
        ];

        $exported = json_decode((string) json_encode($file->export($run, $events)), true);
        self::assertIsArray($exported);
        self::assertSame(['format' => 'xray-trace', 'version' => 1], array_intersect_key($exported, ['format' => 0, 'version' => 0]));

        [$imported, $importedEvents] = $file->import($exported, 'run_imported01');

        self::assertSame('run_imported01', $imported->id);
        self::assertTrue($imported->imported);
        self::assertSame('finished', $imported->status);
        self::assertSame('umbrella', $imported->scenario);
        self::assertSame(['run_imported01', 'run_imported01'], array_map(static fn (Envelope $e): string => $e->runId, $importedEvents));
        self::assertSame([1, 2], array_map(static fn (Envelope $e): int => $e->seq, $importedEvents));
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function invalidTraces(): iterable
    {
        $event = ['v' => 1, 'runId' => 'run_x', 'seq' => 1, 'ts' => 0, 'type' => 'run.started', 'data' => []];

        yield 'not a trace' => [['hello' => 'world'], 'not an X-Ray trace'];
        yield 'newer major version' => [['format' => 'xray-trace', 'version' => 2, 'run' => [], 'events' => [$event]], 'version 2 is not supported'];
        yield 'no events' => [['format' => 'xray-trace', 'version' => 1, 'run' => [], 'events' => []], 'non-empty "events"'];
        yield 'broken event' => [['format' => 'xray-trace', 'version' => 1, 'run' => [], 'events' => [['v' => 1, 'seq' => 'x']]], 'Event #0'];
        yield 'unknown event version' => [['format' => 'xray-trace', 'version' => 1, 'run' => [], 'events' => [[...$event, 'v' => 9]]], 'Unsupported trace event version'];
    }

    /**
     * @param array<mixed> $trace
     */
    #[DataProvider('invalidTraces')]
    public function testInvalidTracesAreRejectedWithAClearError(array $trace, string $message): void
    {
        $this->expectException(InvalidTraceException::class);
        $this->expectExceptionMessage($message);

        new TraceFile()->import($trace, 'run_imported02');
    }
}
