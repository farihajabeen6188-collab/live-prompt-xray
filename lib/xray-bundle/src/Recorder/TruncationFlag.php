<?php

declare(strict_types=1);

namespace XRay\Recorder;

/**
 * @internal mutable flag threaded through one redaction pass
 */
final class TruncationFlag
{
    public bool $truncated = false;
}
