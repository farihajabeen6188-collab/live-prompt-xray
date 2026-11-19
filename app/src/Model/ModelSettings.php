<?php

declare(strict_types=1);

namespace App\Model;

use App\Platform\ScriptedPlatform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The model the demo agent talks to, chosen in the console. Stored in var/ (inside the Docker volume,
 * never in git), readable by the owner only. No connection means Demo Mode (the scripted model).
 */
final class ModelSettings
{
    private ?ModelConnection $connection = null;
    private bool $loaded = false;

    /**
     * @param bool $publicDemo on a public deployment nobody may connect a model: visitors could store
     *                         keys for everyone, or make the server call arbitrary URLs
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/xray/model.%kernel.environment%.json')]
        private readonly string $path,
        #[Autowire(env: 'bool:XRAY_PUBLIC_DEMO')]
        private readonly bool $publicDemo = false,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->publicDemo;
    }

    public function connection(): ?ModelConnection
    {
        if ($this->publicDemo) {
            return null;
        }
        if (!$this->loaded) {
            $this->loaded = true;
            $data = is_file($this->path) ? json_decode((string) file_get_contents($this->path), true) : null;
            if (\is_array($data) && \is_string($data['baseUrl'] ?? null) && \is_string($data['model'] ?? null) && '' !== $data['model']) {
                $this->connection = new ModelConnection((string) ($data['provider'] ?? 'custom'), $data['baseUrl'], (string) ($data['apiKey'] ?? ''), $data['model']);
            }
        }

        return $this->connection;
    }

    /**
     * "demo" (the scripted model answers) or "live" (a connected API answers).
     */
    public function mode(): string
    {
        return null === $this->connection() ? 'demo' : 'live';
    }

    public function modelName(): string
    {
        $connection = $this->connection();

        return null === $connection ? ScriptedPlatform::MODEL : $connection->model;
    }

    public function save(ModelConnection $connection): void
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create '.$directory);
        }
        file_put_contents($this->path, json_encode(['provider' => $connection->provider, 'baseUrl' => $connection->baseUrl, 'apiKey' => $connection->apiKey, 'model' => $connection->model], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), \LOCK_EX);
        chmod($this->path, 0o600);
        $this->connection = $connection;
        $this->loaded = true;
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $this->connection = null;
        $this->loaded = true;
    }
}
