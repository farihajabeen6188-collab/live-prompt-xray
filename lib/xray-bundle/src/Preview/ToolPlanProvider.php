<?php

declare(strict_types=1);

namespace XRay\Preview;

/**
 * Optional: tells the typing preview which tool calls the model will make first, when the app can know
 * (a rules-based or scripted model can; an LLM cannot without being called). Implement it as a service;
 * without one, the preview ranks tools by keyword overlap and labels that as such.
 */
interface ToolPlanProvider
{
    /**
     * @param list<array{role: string, content: string}> $history
     *
     * @return list<array{tool: string, args: array<string, mixed>, reason: string}>|null null when no plan is known
     */
    public function plan(string $prompt, array $history): ?array;

    /**
     * Shown next to the plan, e.g. "scripted demo model".
     */
    public function source(): string;
}
