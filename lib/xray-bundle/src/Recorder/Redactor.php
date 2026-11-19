<?php

declare(strict_types=1);

namespace XRay\Recorder;

/**
 * Rewrites event payloads before they leave the process (storage and transport).
 *
 * - Values under a sensitive key are replaced. A key is sensitive when, lower-cased and stripped of
 *   "-" and "_", it equals or ends with a configured pattern ("api_key" matches "x-api-key",
 *   "token" matches "access_token" but not "inputTokens").
 * - Bearer credentials and well-known API key shapes are masked inside any string.
 * - Strings longer than the limit are truncated; the caller learns it through {@see RedactionResult}.
 */
final readonly class Redactor
{
    public const string MASK = '[redacted]';

    /** @var list<string> */
    private array $patterns;

    /**
     * @param list<string> $keys
     */
    public function __construct(
        array $keys = ['api_key', 'apikey', 'authorization', 'token', 'secret', 'password'],
        private int $maxStringBytes = 8192,
    ) {
        $this->patterns = array_values(array_unique(array_map(self::normalize(...), $keys)));
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        return $this->redactArray($data, new TruncationFlag());
    }

    /**
     * Redacts one string value and reports whether it was truncated.
     */
    public function redactString(string $value): RedactionResult
    {
        $flag = new TruncationFlag();

        return new RedactionResult($this->redactScalar($value, $flag), $flag->truncated);
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalized = self::normalize($key);
        foreach ($this->patterns as $pattern) {
            if ('' !== $pattern && str_ends_with($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function redactArray(array $data, TruncationFlag $flag): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitiveKey($key) && null !== $value && !\is_bool($value)) {
                $out[$key] = self::MASK;
                continue;
            }
            $out[$key] = match (true) {
                \is_array($value) => $this->redactArray($value, $flag),
                \is_string($value) => $this->redactScalar($value, $flag),
                \is_object($value) => $this->redactArray(self::objectToArray($value), $flag),
                default => $value,
            };
        }

        return $out;
    }

    private function redactScalar(string $value, TruncationFlag $flag): string
    {
        $value = (string) preg_replace(
            [
                '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
                '/\b(sk|pk|rk|hf|ghp|gho|xox[abp])[-_][A-Za-z0-9\-_]{16,}/',
            ],
            ['$1 '.self::MASK, self::MASK],
            $value,
        );

        if (\strlen($value) > $this->maxStringBytes) {
            $flag->truncated = true;
            $value = mb_strcut($value, 0, $this->maxStringBytes).'…';
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private static function objectToArray(object $value): array
    {
        if ($value instanceof \JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return \is_array($serialized) ? $serialized : ['value' => $serialized];
        }
        if ($value instanceof \Stringable) {
            return ['value' => (string) $value];
        }

        return get_object_vars($value);
    }

    private static function normalize(string $key): string
    {
        return str_replace(['-', '_', ' '], '', strtolower($key));
    }
}
