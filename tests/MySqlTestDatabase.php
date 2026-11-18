<?php

namespace Tests;

use Dotenv\Dotenv;
use PDO;
use Symfony\Component\Process\Process;

final class MySqlTestDatabase
{
    public readonly string $name;

    private PDO $server;

    private array $schemas = [];

    private array $settings;

    private string $clientDirectory;

    public function __construct()
    {
        $root = dirname(__DIR__);
        $local = is_file($root.'/.env') ? Dotenv::parse(file_get_contents($root.'/.env')) : [];
        $read = fn (string $key, string $default = ''): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) !== false ? getenv($key) : ($local[$key] ?? $default)));
        $this->clientDirectory = rtrim($read('MYSQL_CLIENT_BIN'), '/');
        $this->settings = [
            'DB_HOST' => $read('DB_HOST', '127.0.0.1'), 'DB_PORT' => $read('DB_PORT', '3303'),
            'DB_USERNAME' => $read('DB_USERNAME', 'feedback'), 'DB_PASSWORD' => $read('DB_PASSWORD'),
            'DB_SOCKET' => $read('DB_SOCKET'), 'DB_COLLATION' => 'utf8mb4_0900_bin',
        ];
        $dsn = $this->settings['DB_SOCKET'] !== ''
            ? 'mysql:unix_socket='.$this->settings['DB_SOCKET']
            : 'mysql:host='.$this->settings['DB_HOST'].';port='.$this->settings['DB_PORT'];
        $this->server = new PDO($dsn.';charset=utf8mb4', $this->settings['DB_USERNAME'], $this->settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->name = $this->createSchema();
    }

    public function createSchema(): string
    {
        $name = 'easy_gold_feedback_test_'.bin2hex(random_bytes(6));
        $this->server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin');
        $this->schemas[] = $name;

        return $name;
    }

    public function environment(?string $schema = null): array
    {
        return $this->settings + [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $schema ?? $this->name,
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        ];
    }

    public function restoreBackup(): string
    {
        $restored = $this->createSchema();
        $connection = ['--host='.$this->settings['DB_HOST'], '--port='.$this->settings['DB_PORT'], '--user='.$this->settings['DB_USERNAME']];
        if ($this->settings['DB_SOCKET'] !== '') {
            $connection[] = '--socket='.$this->settings['DB_SOCKET'];
            $connection[] = '--protocol=SOCKET';
        } else {
            $connection[] = '--protocol=TCP';
        }
        $binary = fn (string $name): string => $this->clientDirectory === '' ? $name : $this->clientDirectory.'/'.$name;
        $environment = ['MYSQL_PWD' => $this->settings['DB_PASSWORD']];
        $dump = new Process([$binary('mysqldump'), ...$connection, '--single-transaction', '--no-tablespaces', '--set-gtid-purged=OFF', $this->name], dirname(__DIR__), $environment);
        $dump->setTimeout(45)->mustRun();
        $restore = new Process([$binary('mysql'), ...$connection, $restored], dirname(__DIR__), $environment);
        $restore->setInput($dump->getOutput());
        $restore->setTimeout(45)->mustRun();

        return $restored;
    }

    public function cleanup(): void
    {
        foreach ($this->schemas as $schema) {
            $this->server->exec('DROP DATABASE `'.$schema.'`');
        }
        $this->schemas = [];
    }
}
