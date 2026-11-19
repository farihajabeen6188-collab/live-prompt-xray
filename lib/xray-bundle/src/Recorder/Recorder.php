<?php

declare(strict_types=1);

namespace XRay\Recorder;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use XRay\Event\Envelope;
use XRay\Event\RunFinished;
use XRay\Event\RunStarted;
use XRay\Event\TraceEvent;
use XRay\Storage\RunRecord;
use XRay\Storage\TraceStore;
use XRay\Transport\EventPublisher;

/**
 * Turns trace events into ordered, redacted envelopes, stores them and publishes them live.
 *
 * Safe by construction: nothing in here may break the agent run it observes. Every failure
 * (store, transport, a bug in a payload) is caught and logged as a warning.
 */
final readonly class Recorder
{
    public function __construct(
        private RunContext $context,
        private Redactor $redactor,
        private TraceStore $store,
        private EventPublisher $publisher,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function beginRun(string $runId, RunStarted $event): void
    {
        $this->safely(function () use ($runId, $event): void {
            $this->context->begin($runId, $event->model);
            $this->guard(fn () => $this->store->beginRun(new RunRecord(
                id: $runId,
                prompt: $this->redactor->redactString($event->prompt)->value,
                agent: $event->agent,
                model: $event->model,
                mode: $event->mode,
                scenario: $event->scenario,
                startedAt: new \DateTimeImmutable(),
            )), 'store');
            $this->emit($event);
        });
    }

    public function emit(TraceEvent $event): void
    {
        $this->safely(function () use ($event): void {
            $run = $this->context->current();
            if (null === $run) {
                return;
            }
            $envelope = new Envelope($run->id, $run->nextSeq(), $run->elapsedMs($this->context->now()), $event->type(), $this->redactor->redact($event->data()));
            if ($run->preview) {
                $run->captured[] = $envelope->toArray();

                return;
            }
            $this->guard(fn () => $this->store->append($envelope), 'store');
            $this->guard(fn () => $this->publisher->publish($run->id, $envelope->toArray()), 'transport');
        });
    }

    public function endRun(RunFinished $event): void
    {
        $this->safely(function () use ($event): void {
            $run = $this->context->current();
            if (null === $run) {
                return;
            }
            $this->emit($event);
            $this->guard(fn () => $this->store->finishRun($run->id, $event->ok ? 'finished' : 'failed', $this->redactor->redact($event->data())), 'store');
            $this->context->end();
        });
    }

    public function isRecording(): bool
    {
        return null !== $this->context->current();
    }

    public function context(): RunContext
    {
        return $this->context;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    private function guard(\Closure $operation, string $where): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->logger->warning('X-Ray {where} failed, the agent run continues: {message}', ['where' => $where, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function safely(\Closure $operation): void
    {
        $this->guard($operation, 'recorder');
    }
}
