<?php

declare(strict_types=1);

namespace XRay\Storage;

final readonly class RunRecord
{
    /**
     * @param 'running'|'finished'|'failed' $status
     * @param array<string, mixed>          $summary data of the run.finished event, once there is one
     */
    public function __construct(
        public string $id,
        public string $prompt,
        public string $agent,
        public string $model,
        public string $mode,
        public ?string $scenario,
        public \DateTimeImmutable $startedAt,
        public string $status = 'running',
        public array $summary = [],
        public bool $imported = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'agent' => $this->agent,
            'model' => $this->model,
            'mode' => $this->mode,
            'scenario' => $this->scenario,
            'startedAt' => $this->startedAt->format(\DATE_ATOM),
            'status' => $this->status,
            'summary' => $this->summary,
            'imported' => $this->imported,
        ];
    }
}
