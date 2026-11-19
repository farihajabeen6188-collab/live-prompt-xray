<?php

declare(strict_types=1);

namespace XRay\Transport;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class MercurePublisher implements EventPublisher
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(string $runId, array $event): bool
    {
        try {
            $this->hub->publish(new Update($this->topic($runId), json_encode($event, \JSON_THROW_ON_ERROR)));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('X-Ray could not publish a trace event: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }

    public function topic(string $runId): string
    {
        return 'xray/runs/'.$runId;
    }
}
