<?php

declare(strict_types=1);

namespace App;

use Nette\Neon\Neon;

final class Config
{
    /** @var array<int, array{id: string, login: string, password: string}> */
    private array $databoxes;
    private bool $production;

    /**
     * @param array<int, array{id: string, login: string, password: string}> $databoxes
     */
    private function __construct(bool $production, array $databoxes)
    {
        $this->production = $production;
        $this->databoxes = $databoxes;
    }

    public static function load(string $path): self
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(sprintf('Config file not found: %s', $path));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Failed to read config file: %s', $path));
        }

        /** @var array<string, mixed> $data */
        $data = Neon::decode($content);

        if (!isset($data['production']) || !is_bool($data['production'])) {
            throw new \RuntimeException('Config: "production" must be a boolean');
        }

        if (!isset($data['databoxes']) || !is_array($data['databoxes'])) {
            throw new \RuntimeException('Config: "databoxes" must be an array');
        }

        $databoxes = [];
        foreach ($data['databoxes'] as $i => $box) {
            foreach (['id', 'login', 'password'] as $key) {
                if (!isset($box[$key]) || !is_string($box[$key])) {
                    throw new \RuntimeException(sprintf('Config: databoxes[%d].%s must be a string', $i, $key));
                }
            }
            $databoxes[] = [
                'id' => $box['id'],
                'login' => $box['login'],
                'password' => $box['password'],
            ];
        }

        return new self($data['production'], $databoxes);
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    /**
     * @return array<int, array{id: string, login: string, password: string}>
     */
    public function getDataboxes(): array
    {
        return $this->databoxes;
    }
}
