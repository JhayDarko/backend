<?php

namespace App\Services\Evaluation;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EvaluationRuntime
{
    public function assertSafeDatabaseConfiguration(): void
    {
        $settings = config('evaluation.database');
        $driver = is_array($settings) ? ($settings['connection'] ?? null) : null;
        $database = is_array($settings) ? ($settings['database'] ?? null) : null;

        if (! is_string($driver) || trim($driver) === ''
            || ! is_string($database) || trim($database) === '') {
            throw new RuntimeException('EVAL_DB_CONNECTION and EVAL_DB_DATABASE must be explicitly configured.');
        }

        $evaluation = config('database.connections.evaluation');
        $default = config('database.connections.'.config('database.default'));

        if (! is_array($evaluation) || ! is_array($default)) {
            throw new RuntimeException('Evaluation and default database connections must be configured.');
        }

        if ($driver === 'sqlite') {
            $evaluationPath = $this->sqlitePath($database);
            $defaultPath = $this->sqlitePath((string) ($default['database'] ?? ''));

            if ($evaluationPath === $defaultPath) {
                throw new RuntimeException('The evaluation SQLite database must differ from the default database.');
            }

            return;
        }

        foreach (['host' => 'EVAL_DB_HOST', 'port' => 'EVAL_DB_PORT', 'username' => 'EVAL_DB_USERNAME', 'password' => 'EVAL_DB_PASSWORD'] as $key => $variable) {
            if (! array_key_exists($key, $settings) || $settings[$key] === null) {
                throw new RuntimeException("{$variable} must be explicitly configured.");
            }
        }
        $host = trim((string) $settings['host']);

        $defaultHost = (string) ($default['host'] ?? '');
        $defaultDatabase = (string) ($default['database'] ?? '');
        if (is_string($default['url'] ?? null) && $default['url'] !== '') {
            $defaultUrl = parse_url($default['url']);
            if (is_array($defaultUrl)) {
                $defaultHost = (string) ($defaultUrl['host'] ?? $defaultHost);
                $defaultDatabase = ltrim((string) ($defaultUrl['path'] ?? ''), '/') ?: $defaultDatabase;
            }
        }

        if (strcasecmp($host, $defaultHost) === 0
            && strcasecmp($database, $defaultDatabase) === 0) {
            throw new RuntimeException('The evaluation host and database must differ from the default connection.');
        }
    }

    public function activate(): void
    {
        $this->assertSafeDatabaseConfiguration();
        $settings = config('evaluation.database');
        $driver = (string) $settings['connection'];
        $database = (string) $settings['database'];

        if ($driver === 'sqlite') {
            config([
                'database.connections.evaluation' => [
                    'driver' => 'sqlite',
                    'database' => $database,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ]);
        } else {
            config([
                'database.connections.evaluation.driver' => $driver,
                'database.connections.evaluation.host' => $settings['host'],
                'database.connections.evaluation.port' => $settings['port'],
                'database.connections.evaluation.database' => $database,
                'database.connections.evaluation.username' => $settings['username'],
                'database.connections.evaluation.password' => $settings['password'],
            ]);
        }
        config([
            'database.default' => 'evaluation',
            'cache.default' => 'evaluation',
            'queue.default' => 'redis',
            'queue.connections.redis' => config('queue.connections.evaluation'),
            'queue.batching.database' => 'evaluation',
            'queue.failed.database' => 'evaluation',
        ]);

        DB::purge('evaluation');
        DB::setDefaultConnection('evaluation');
    }

    private function sqlitePath(string $database): string
    {
        if ($database === ':memory:') {
            return ':memory:';
        }

        $path = str_starts_with($database, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $database) === 1
            ? $database
            : base_path($database);

        return strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) realpath($path) ?: $path));
    }
}
