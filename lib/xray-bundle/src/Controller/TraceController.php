<?php

declare(strict_types=1);

namespace XRay\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use XRay\Diff\TraceDiffer;
use XRay\Event\Envelope;
use XRay\Recorder\RunContext;
use XRay\Replay\InvalidTraceException;
use XRay\Replay\TraceFile;
use XRay\Storage\RunRecord;
use XRay\Storage\TraceStore;

/**
 * Stored runs as JSON: list, one run with its events, export, import and diff.
 */
final readonly class TraceController
{
    private const int MAX_IMPORT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private TraceStore $store,
        private TraceFile $traceFile,
        private TraceDiffer $differ,
    ) {
    }

    public function list(Request $request): JsonResponse
    {
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));

        return new JsonResponse(['runs' => array_map(static fn (RunRecord $run): array => $run->toArray(), $this->store->runs($limit))]);
    }

    public function show(string $id): JsonResponse
    {
        [$run, $events] = $this->load($id);

        return new JsonResponse(['run' => $run->toArray(), 'events' => array_map(static fn (Envelope $e): array => $e->toArray(), $events)]);
    }

    public function export(string $id): Response
    {
        [$run, $events] = $this->load($id);
        $response = new JsonResponse($this->traceFile->export($run, $events));
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="xray-%s.json"', $run->id));

        return $response;
    }

    public function import(Request $request): JsonResponse
    {
        $body = $request->getContent();
        if ('' === $body || \strlen($body) > self::MAX_IMPORT_BYTES) {
            throw new BadRequestHttpException('Send a trace file of at most 10 MB as the request body.');
        }
        try {
            $trace = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($trace)) {
                throw new InvalidTraceException('The trace file must be a JSON object.');
            }
            [$run, $events] = $this->traceFile->import($trace, RunContext::newRunId());
        } catch (\JsonException $e) {
            return new JsonResponse(['error' => 'The file is not valid JSON: '.$e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidTraceException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->store->save($run, $events);

        return new JsonResponse(['run' => $run->toArray()], Response::HTTP_CREATED);
    }

    public function diff(Request $request): JsonResponse
    {
        [$runA, $a] = $this->load((string) $request->query->get('a'));
        [$runB, $b] = $this->load((string) $request->query->get('b'));

        return new JsonResponse(['runs' => [$runA->toArray(), $runB->toArray()], 'diff' => $this->differ->diff($a, $b)]);
    }

    /**
     * @return array{RunRecord, list<Envelope>}
     */
    private function load(string $id): array
    {
        $run = RunContext::isValidRunId($id) ? $this->store->run($id) : null;
        if (null === $run) {
            throw new NotFoundHttpException(\sprintf('Run "%s" not found.', $id));
        }

        return [$run, $this->store->events($id)];
    }
}
