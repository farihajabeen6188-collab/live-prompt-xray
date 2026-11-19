<?php

declare(strict_types=1);

namespace XRay\Storage;

use XRay\Event\Envelope;

/**
 * Stores traces in one SQLite file. WAL mode lets the live stream read while a run writes.
 */
final class SqliteTraceStore implements TraceStore
{
    private ?\PDO $pdo = null;

    public function __construct(private readonly string $path)
    {
    }

    public function beginRun(RunRecord $run): void
    {
        $this->upsertRun($run);
    }

    public function append(Envelope $event): void
    {
        $this->db()->prepare('INSERT OR IGNORE INTO xray_events (run_id, seq, ts, type, data) VALUES (?, ?, ?, ?, ?)')
            ->execute([$event->runId, $event->seq, $event->ts, $event->type, json_encode($event->data, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE)]);
    }

    public function finishRun(string $runId, string $status, array $summary): void
    {
        $this->db()->prepare('UPDATE xray_runs SET status = ?, summary = ? WHERE id = ?')
            ->execute([$status, json_encode($summary, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE), $runId]);
    }

    public function run(string $runId): ?RunRecord
    {
        $statement = $this->db()->prepare('SELECT * FROM xray_runs WHERE id = ?');
        $statement->execute([$runId]);
        /** @var array<string, scalar|null>|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return false === $row ? null : self::hydrate($row);
    }

    public function runs(int $limit = 50): array
    {
        $statement = $this->db()->prepare('SELECT * FROM xray_runs ORDER BY started_at DESC, rowid DESC LIMIT ?');
        $statement->bindValue(1, $limit, \PDO::PARAM_INT);
        $statement->execute();
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(self::hydrate(...), $rows);
    }

    public function events(string $runId, int $afterSeq = 0): array
    {
        $statement = $this->db()->prepare('SELECT run_id, seq, ts, type, data FROM xray_events WHERE run_id = ? AND seq > ? ORDER BY seq');
        $statement->execute([$runId, $afterSeq]);
        $events = [];
        /** @var array{run_id: string, seq: int, ts: int, type: string, data: string} $row */
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            /** @var array<string, mixed> $data */
            $data = json_decode($row['data'], true, 512, \JSON_THROW_ON_ERROR);
            $events[] = new Envelope($row['run_id'], (int) $row['seq'], (int) $row['ts'], $row['type'], $data);
        }

        return $events;
    }

    public function save(RunRecord $run, array $events): void
    {
        $db = $this->db();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM xray_events WHERE run_id = ?')->execute([$run->id]);
            $this->upsertRun($run);
            foreach ($events as $event) {
                $this->append($event);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    private function upsertRun(RunRecord $run): void
    {
        $this->db()->prepare('INSERT OR REPLACE INTO xray_runs (id, prompt, agent, model, mode, scenario, started_at, status, summary, imported) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $run->id,
                $run->prompt,
                $run->agent,
                $run->model,
                $run->mode,
                $run->scenario,
                $run->startedAt->format('Y-m-d\TH:i:s.uP'),
                $run->status,
                json_encode($run->summary, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE),
                (int) $run->imported,
            ]);
    }

    /**
     * @param array<string, scalar|null> $row
     */
    private static function hydrate(array $row): RunRecord
    {
        /** @var array<string, mixed> $summary */
        $summary = json_decode((string) $row['summary'], true, 512, \JSON_THROW_ON_ERROR);
        $status = (string) $row['status'];

        return new RunRecord(
            id: (string) $row['id'],
            prompt: (string) $row['prompt'],
            agent: (string) $row['agent'],
            model: (string) $row['model'],
            mode: (string) $row['mode'],
            scenario: null === $row['scenario'] ? null : (string) $row['scenario'],
            startedAt: new \DateTimeImmutable((string) $row['started_at']),
            status: \in_array($status, ['running', 'finished', 'failed'], true) ? $status : 'failed',
            summary: $summary,
            imported: (bool) $row['imported'],
        );
    }

    private function db(): \PDO
    {
        if (null !== $this->pdo) {
            return $this->pdo;
        }
        $directory = \dirname($this->path);
        if (':memory:' !== $this->path && !is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException(\sprintf('Cannot create the X-Ray trace directory "%s".', $directory));
        }
        $pdo = new \PDO('sqlite:'.$this->path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 3000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS xray_runs (
            id TEXT PRIMARY KEY, prompt TEXT NOT NULL, agent TEXT NOT NULL, model TEXT NOT NULL, mode TEXT NOT NULL,
            scenario TEXT NULL, started_at TEXT NOT NULL, status TEXT NOT NULL, summary TEXT NOT NULL, imported INTEGER NOT NULL DEFAULT 0
        )');
        $pdo->exec('CREATE INDEX IF NOT EXISTS xray_runs_started ON xray_runs (started_at)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS xray_events (
            run_id TEXT NOT NULL, seq INTEGER NOT NULL, ts INTEGER NOT NULL, type TEXT NOT NULL, data TEXT NOT NULL,
            PRIMARY KEY (run_id, seq)
        )');

        return $this->pdo = $pdo;
    }
}
