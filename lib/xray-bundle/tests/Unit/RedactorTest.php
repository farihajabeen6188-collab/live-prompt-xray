<?php

declare(strict_types=1);

namespace XRay\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XRay\Recorder\Redactor;

final class RedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function keys(): iterable
    {
        yield 'api_key' => ['api_key', true];
        yield 'x-api-key header' => ['X-Api-Key', true];
        yield 'apiKey' => ['apiKey', true];
        yield 'authorization' => ['Authorization', true];
        yield 'access_token' => ['access_token', true];
        yield 'client secret' => ['client_secret', true];
        yield 'password' => ['password', true];
        yield 'token counts stay visible' => ['inputTokens', false];
        yield 'max_tokens stays visible' => ['max_tokens', false];
        yield 'city' => ['city', false];
    }

    #[DataProvider('keys')]
    public function testSensitiveKeys(string $key, bool $sensitive): void
    {
        self::assertSame($sensitive, (new Redactor())->isSensitiveKey($key));
    }

    public function testNestedValuesAndCredentialShapesAreMasked(): void
    {
        $redacted = (new Redactor())->redact([
            'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
            'note' => 'call with Bearer eyJhbGciOiJIUzI1NiJ9.payload and sk-proj-abcdefghijklmnopqrstu',
            'nested' => [['password' => 'hunter2', 'user' => 'ada']],
            'inputTokens' => 42,
        ]);

        self::assertSame(['Authorization' => Redactor::MASK, 'Accept' => 'application/json'], $redacted['headers']);
        self::assertSame('call with Bearer [redacted] and [redacted]', $redacted['note']);
        self::assertSame([['password' => Redactor::MASK, 'user' => 'ada']], $redacted['nested']);
        self::assertSame(42, $redacted['inputTokens']);
    }

    public function testTheKeyListIsConfigurable(): void
    {
        $redactor = new Redactor(['iban']);

        self::assertSame(['iban' => Redactor::MASK, 'password' => 'visible'], $redactor->redact(['iban' => 'DE00', 'password' => 'visible']));
    }

    public function testLongStringsAreTruncated(): void
    {
        $redactor = new Redactor(maxStringBytes: 10);

        $result = $redactor->redactString(str_repeat('a', 50));

        self::assertTrue($result->truncated);
        self::assertSame(str_repeat('a', 10).'…', $result->value);
        self::assertFalse($redactor->redactString('short')->truncated);
    }
}
