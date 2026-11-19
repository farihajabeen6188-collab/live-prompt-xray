<?php

declare(strict_types=1);

namespace XRay\Instrumentation;

use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\Stream\ErrorEvent;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use XRay\Event\RunError;
use XRay\Recorder\Recorder;

/**
 * Observes a streamed model turn delta by delta. Never rewrites or skips a delta.
 *
 * @internal
 */
final class TurnStreamListener extends AbstractStreamListener
{
    public function __construct(
        private readonly TurnRecorder $turn,
        private readonly Recorder $recorder,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        try {
            $delta = $event->getDelta();
            match (true) {
                $delta instanceof TextDelta => $this->turn->text($delta->getText()),
                $delta instanceof ToolCallComplete => $this->turn->toolCalls($delta->getToolCalls()),
                $delta instanceof TokenUsageInterface => $this->turn->usage($delta),
                default => null,
            };
        } catch (\Throwable) {
            // Observing must never break the stream; the Recorder already logs its own failures.
        }
    }

    public function onComplete(CompleteEvent $event): void
    {
        try {
            $this->turn->close();
        } catch (\Throwable) {
            // see onDelta()
        }
    }

    public function onError(ErrorEvent $event): void
    {
        try {
            $run = $this->recorder->context()->current();
            if (null !== $run) {
                ++$run->errors;
            }
            $this->recorder->emit(new RunError('platform', $event->getError()->getMessage()));
            $this->turn->close();
        } catch (\Throwable) {
            // see onDelta()
        }
    }
}
