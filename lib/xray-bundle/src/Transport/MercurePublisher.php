<?php

declare(strict_types=1);

namespace XRay\Transport;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publishes each event to the topic "xray/runs/{runId}". The console follows every run with
 * one subscription to the URI template "xray/runs/{runId}".
 */
final readonly class MercurePublisher implements EventPublisher
{
    public const string TOPIC_PREFIX = 'xray/runs/';

    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(string $runId, array $event): bool
    {
        try {
            $this->hub->publish(new Update(self::TOPIC_PREFIX.$runId, json_encode($event, \JSON_THROW_ON_ERROR)));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('X-Ray could not publish a trace event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }
}
