<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use XRay\Transport\EventPublisher;

final class ConsoleController extends AbstractController
{
    public function __construct(private readonly EventPublisher $publisher)
    {
    }

    public function console(): Response
    {
        return $this->render('@XRay/console.html.twig', [
            'topic' => $this->publisher->topic('spike'),
        ]);
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Phase 0 spike: pushes one event through the realtime transport.
     */
    public function spike(Request $request): JsonResponse
    {
        $event = [
            'v' => 1,
            'runId' => 'spike',
            'seq' => $request->request->getInt('seq', 1),
            'ts' => 0,
            'type' => 'spike.ping',
            'data' => ['sentAt' => (int) (microtime(true) * 1000)],
        ];

        return new JsonResponse(['published' => $this->publisher->publish('spike', $event), 'event' => $event]);
    }
}
