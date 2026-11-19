<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class RunStarted implements TraceEvent
{
    public function __construct(
        public string $prompt,
        public string $agent,
        public string $model,
        public string $mode,
        public ?string $scenario = null,
    ) {
    }

    public function type(): string
    {
        return 'run.started';
    }

    public function data(): array
    {
        return ['prompt' => $this->prompt, 'agent' => $this->agent, 'model' => $this->model, 'mode' => $this->mode, 'scenario' => $this->scenario];
    }
}
