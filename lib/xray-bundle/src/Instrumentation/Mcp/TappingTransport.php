<?php

declare(strict_types=1);

namespace XRay\Instrumentation\Mcp;

use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Transport\HeaderAwareTransportInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use XRay\Event\McpFrame;
use XRay\Recorder\Recorder;
use XRay\Recorder\Run;

/**
 * Taps the JSON-RPC frames an MCP client exchanges with a server, at the transport level.
 *
 * The SDK's Protocol hands every outgoing message to send() and receives every incoming one
 * through the onMessage() callback, so both directions are the real wire frames.
 * A tools/call request is correlated with the agent's tool call that is waiting for it.
 */
final class TappingTransport implements HeaderAwareTransportInterface
{
    /** @var array<int|string, array{at: int, method: ?string, callId: ?string}> */
    private array $pending = [];

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly Recorder $recorder,
        private readonly string $server,
        private readonly McpToolDirectory $directory,
    ) {
    }

    public function connect(): void
    {
        $this->inner->connect();
    }

    public function send(string $data): void
    {
        $this->tap($data, 'out');
        $this->inner->send($data);
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        return $this->inner->runRequest($fiber, $onProgress);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function onInitialize(callable $callback): void
    {
        $this->inner->onInitialize($callback);
    }

    public function onMessage(callable $callback): void
    {
        $this->inner->onMessage(function (string $message) use ($callback): void {
            $this->tap($message, 'in');
            $callback($message);
        });
    }

    public function onError(callable $callback): void
    {
        $this->inner->onError($callback);
    }

    public function onClose(callable $callback): void
    {
        $this->inner->onClose($callback);
    }

    public function setState(ClientStateInterface $state): void
    {
        $this->inner->setState($state);
    }

    public function onHeaders(callable $callback): void
    {
        if ($this->inner instanceof HeaderAwareTransportInterface) {
            $this->inner->onHeaders($callback);
        }
    }

    private function tap(string $data, string $direction): void
    {
        try {
            $run = $this->recorder->context()->current();
            if (null === $run) {
                return;
            }
            $decoded = json_decode($data, true);
            if (!\is_array($decoded)) {
                return;
            }
            // A JSON-RPC batch is a list of messages.
            foreach (array_is_list($decoded) ? $decoded : [$decoded] as $message) {
                if (\is_array($message)) {
                    /** @var array<string, mixed> $message */
                    $this->record($run, $message, $direction);
                }
            }
        } catch (\Throwable) {
            // The frame still goes through; X-Ray must never break MCP traffic.
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function record(Run $run, array $message, string $direction): void
    {
        $id = $message['id'] ?? null;
        $id = \is_int($id) || \is_string($id) ? $id : null;
        $method = \is_string($message['method'] ?? null) ? $message['method'] : null;
        $now = $this->recorder->context()->now();

        if (null !== $method) {
            $kind = null === $id ? 'notification' : 'request';
            $callId = 'tools/call' === $method && 'out' === $direction ? $this->correlate($run, $message) : null;
            if (null !== $id) {
                $this->pending[$id] = ['at' => $now, 'method' => $method, 'callId' => $callId];
            }
            $this->recorder->emit(new McpFrame($this->server, $direction, $kind, $method, $id, $message, $callId));

            return;
        }

        $request = null === $id ? null : ($this->pending[$id] ?? null);
        if (null !== $id) {
            unset($this->pending[$id]);
        }
        $isError = isset($message['error']) || true === (\is_array($message['result'] ?? null) ? ($message['result']['isError'] ?? false) : false);
        if ('tools/list' === ($request['method'] ?? null) && \is_array($message['result']['tools'] ?? null)) {
            $this->learnTools($message['result']['tools']);
        }
        $this->recorder->emit(new McpFrame(
            $this->server,
            $direction,
            $isError ? 'error' : 'response',
            $request['method'] ?? null,
            $id,
            $message,
            $request['callId'] ?? null,
            null === $request ? null : intdiv(max(0, $now - $request['at']), 1_000_000),
        ));
    }

    /**
     * @param array<string, mixed> $message
     */
    private function correlate(Run $run, array $message): ?string
    {
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        foreach ($run->awaitingMcp as $callId => $call) {
            if ($call['name'] === $name || str_ends_with($call['name'], '_'.$name)) {
                unset($run->awaitingMcp[$callId]);

                return $callId;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $tools
     */
    private function learnTools(array $tools): void
    {
        foreach ($tools as $tool) {
            if (\is_array($tool) && \is_string($tool['name'] ?? null)) {
                $this->directory->learn($tool['name'], $this->server);
            }
        }
    }
}
