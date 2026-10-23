<?php

namespace Tests\Unit\Services\Evaluation;

use App\Services\Evaluation\EvaluationRuntime;
use RuntimeException;
use Tests\TestCase;

final class EvaluationRuntimeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $environment = [];

    private string $originalDatabaseDefault;

    private string $originalPostgresHost;

    private string $originalPostgresDatabase;

    /** @var array<string, mixed> */
    private array $originalEvaluationDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabaseDefault = (string) config('database.default');
        $this->originalPostgresHost = (string) config('database.connections.pgsql.host');
        $this->originalPostgresDatabase = (string) config('database.connections.pgsql.database');
        $this->originalEvaluationDatabase = config('evaluation.database');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        config([
            'database.default' => $this->originalDatabaseDefault,
            'database.connections.pgsql.host' => $this->originalPostgresHost,
            'database.connections.pgsql.database' => $this->originalPostgresDatabase,
            'evaluation.database' => $this->originalEvaluationDatabase,
        ]);

        parent::tearDown();
    }

    public function test_it_rejects_missing_evaluation_database_environment(): void
    {
        $this->setEnv('EVAL_DB_CONNECTION', null);
        $this->setEnv('EVAL_DB_DATABASE', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EVAL_DB_CONNECTION and EVAL_DB_DATABASE must be explicitly configured');

        app(EvaluationRuntime::class)->assertSafeDatabaseConfiguration();
    }

    public function test_it_rejects_an_evaluation_sqlite_database_that_matches_default(): void
    {
        $this->setEnv('EVAL_DB_CONNECTION', 'sqlite');
        $this->setEnv('EVAL_DB_DATABASE', ':memory:');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must differ from the default database');

        app(EvaluationRuntime::class)->assertSafeDatabaseConfiguration();
    }

    public function test_it_rejects_a_matching_evaluation_host_and_database(): void
    {
        $this->setEnv('EVAL_DB_CONNECTION', 'pgsql');
        $this->setEnv('EVAL_DB_HOST', 'same-db');
        $this->setEnv('EVAL_DB_PORT', '5432');
        $this->setEnv('EVAL_DB_DATABASE', 'factnode');
        $this->setEnv('EVAL_DB_USERNAME', 'eval');
        $this->setEnv('EVAL_DB_PASSWORD', '');
        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => 'same-db',
            'database.connections.pgsql.database' => 'factnode',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('host and database must differ');

        app(EvaluationRuntime::class)->assertSafeDatabaseConfiguration();
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environment)) {
            $this->environment[$name] = getenv($name);
        }
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            $this->setConfigurationValue($name, null);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        $this->setConfigurationValue($name, $value);
    }

    private function setConfigurationValue(string $name, ?string $value): void
    {
        $key = match ($name) {
            'EVAL_DB_CONNECTION' => 'evaluation.database.connection',
            'EVAL_DB_HOST' => 'evaluation.database.host',
            'EVAL_DB_PORT' => 'evaluation.database.port',
            'EVAL_DB_DATABASE' => 'evaluation.database.database',
            'EVAL_DB_USERNAME' => 'evaluation.database.username',
            'EVAL_DB_PASSWORD' => 'evaluation.database.password',
            default => null,
        };
        if ($key !== null) {
            config([$key => $value]);
        }
    }
}
