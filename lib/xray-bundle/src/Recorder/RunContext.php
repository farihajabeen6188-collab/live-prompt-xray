<?php

declare(strict_types=1);

namespace XRay\Recorder;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the run being recorded in this process. One run at a time per request or worker message.
 */
final class RunContext implements ResetInterface
{
    private ?Run $run = null;

    /** @var array{runId: string, scenario: ?string, mode: string}|null */
    private ?array $prepared = null;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): int)|null $clock monotonic nanoseconds
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => hrtime(true);
    }

    /**
     * Labels the next agent call: its run id (so a viewer can subscribe before it starts), scenario and mode.
     */
    public function prepare(string $runId, ?string $scenario = null, string $mode = 'live'): void
    {
        if (!self::isValidRunId($runId)) {
            throw new \InvalidArgumentException(\sprintf('Invalid run id "%s".', $runId));
        }
        $this->prepared = ['runId' => $runId, 'scenario' => $scenario, 'mode' => $mode];
    }

    /**
     * @return array{runId: string, scenario: ?string, mode: string}
     */
    public function takePrepared(): array
    {
        $prepared = $this->prepared ?? ['runId' => self::newRunId(), 'scenario' => null, 'mode' => 'live'];
        $this->prepared = null;

        return $prepared;
    }

    public function begin(string $runId, string $model): Run
    {
        return $this->run = new Run($runId, $model, $this->now());
    }

    /**
     * Starts a dry run: the decorators record as usual, the Recorder keeps the events in memory.
     */
    public function beginPreview(string $model): Run
    {
        return $this->run = new Run('preview', $model, $this->now(), true);
    }

    public function current(): ?Run
    {
        return $this->run;
    }

    public function end(): void
    {
        $this->run = null;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function reset(): void
    {
        $this->run = null;
        $this->prepared = null;
    }

    public static function newRunId(): string
    {
        return 'run_'.bin2hex(random_bytes(10));
    }

    public static function isValidRunId(string $runId): bool
    {
        return 1 === preg_match('/^run_[A-Za-z0-9]{8,40}$/', $runId);
    }
}
