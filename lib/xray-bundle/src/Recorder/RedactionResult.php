<?php

declare(strict_types=1);

namespace XRay\Recorder;

final readonly class RedactionResult
{
    public function __construct(
        public string $value,
        public bool $truncated,
    ) {
    }
}
