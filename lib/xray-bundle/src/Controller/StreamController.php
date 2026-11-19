<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use XRay\Event\Envelope;
use XRay\Recorder\RunContext;
use XRay\Storage\TraceStore;

/**
 * Server-Sent Events from the trace store:
 *  - /stream follows a run while it is recorded (the transport-independent live path),
 *  - /replay plays a stored run back with its original timing, at a chosen speed.
 */
final readonly class StreamController
{
    private const int POLL_US = 40_000;
    private const int MAX_GAP_MS = 1500;

    public function __construct(private TraceStore $store)
    {
    }

    public function stream(string $id, Request $request): StreamedResponse
    {
        self::assertRunId($id);
        $after = max(0, (int) ($request->headers->get('Last-Event-ID') ?? $request->query->getInt('after')));
        $timeout = min(600, max(1, $request->query->getInt('timeout', 300)));

        return $this->sse(function () use ($id, $after, $timeout): void {
            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline && 0 === connection_aborted()) {
                foreach ($this->store->events($id, $after) as $event) {
                    self::send($event);
                    $after = $event->seq;
                    if ('run.finished' === $event->type) {
                        return;
                    }
                }
                usleep(self::POLL_US);
            }
        });
    }

    public function replay(string $id, Request $request): StreamedResponse
    {
        self::assertRunId($id);
        $events = $this->store->events($id);
        if ([] === $events) {
            throw new NotFoundHttpException(\sprintf('Run "%s" has no recorded events.', $id));
        }
        $speed = min(8.0, max(0.25, (float) $request->query->get('speed', '1')));

        return $this->sse(static function () use ($events, $speed): void {
            $previousTs = $events[0]->ts;
            foreach ($events as $event) {
                $gapMs = min(self::MAX_GAP_MS, max(0, $event->ts - $previousTs)) / $speed;
                if ($gapMs > 0) {
                    usleep((int) ($gapMs * 1000));
                }
                if (0 !== connection_aborted()) {
                    return;
                }
                self::send($event);
                $previousTs = $event->ts;
            }
        });
    }

    private function sse(\Closure $body): StreamedResponse
    {
        return new StreamedResponse(static function () use ($body): void {
            echo ": x-ray\n\n";
            flush();
            $body();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private static function send(Envelope $event): void
    {
        echo 'id: ', $event->seq, "\n", 'data: ', json_encode($event->toArray(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE), "\n\n";
        flush();
    }

    private static function assertRunId(string $id): void
    {
        if (!RunContext::isValidRunId($id)) {
            throw new NotFoundHttpException('Unknown run.');
        }
    }
}
