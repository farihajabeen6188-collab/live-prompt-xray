<?php

declare(strict_types=1);

namespace XRay\Event;

final readonly class PromptSent implements TraceEvent
{
    /**
     * @param list<array{role: string, content: string, truncated: bool, toolCallId?: string}> $messages
     * @param list<array{name: string, description: string}>                                 $tools
     */
    public function __construct(
        public array $messages,
        public array $tools,
        public int $turn,
        public string $model,
    ) {
    }

    public function type(): string
    {
        return 'prompt.sent';
    }

    public function data(): array
    {
        return ['messages' => $this->messages, 'tools' => $this->tools, 'turn' => $this->turn, 'model' => $this->model];
    }
}
