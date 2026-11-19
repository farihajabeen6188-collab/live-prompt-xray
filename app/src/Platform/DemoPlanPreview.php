<?php

declare(strict_types=1);

namespace App\Platform;

use App\Model\ModelSettings;
use XRay\Preview\ToolPlanProvider;

/**
 * In Demo Mode the "model" is rules, so the typing preview can show exactly which tools it will call first.
 * With a real model connected there is no honest prediction: the preview falls back to keyword ranking.
 */
final readonly class DemoPlanPreview implements ToolPlanProvider
{
    public function __construct(
        private ModelSettings $settings,
        private ScriptBook $scripts,
        private DemoPlanner $planner,
    ) {
    }

    public function plan(string $prompt, array $history): ?array
    {
        if (null !== $this->settings->connection()) {
            return null;
        }
        if ($this->scripts->isScenarioPrompt($prompt)) {
            return array_map(static fn (array $call): array => [...$call, 'reason' => 'showcase scenario script'], $this->scripts->turnFor($prompt, 0, [])->calls);
        }

        return $this->planner->plan($prompt);
    }

    public function source(): string
    {
        return 'scripted demo model (rules, so the plan is exact)';
    }
}
