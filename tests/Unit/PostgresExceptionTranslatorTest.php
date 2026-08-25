<?php

namespace Tests\Unit;

use App\Application\Exceptions\ConcurrencyConflict;
use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;

class PostgresExceptionTranslatorTest extends TestCase
{
    public function test_integrity_sqlstates_are_translated(): void
    {
        $translator = new PostgresExceptionTranslator();

        foreach (['23505', '23503', '23514', 'P0001'] as $sqlState) {
            $exception = $this->queryException($sqlState);

            $translated = $translator->translate($exception);

            $this->assertInstanceOf(
                IntegrityConstraintViolation::class,
                $translated
            );

            $this->assertSame($sqlState, $translated->sqlState);
            $this->assertSame($exception, $translated->getPrevious());
        }
    }

    public function test_concurrency_sqlstates_are_translated(): void
    {
        $translator = new PostgresExceptionTranslator();

        foreach (['40001', '40P01'] as $sqlState) {
            $exception = $this->queryException($sqlState);

            $translated = $translator->translate($exception);

            $this->assertInstanceOf(
                ConcurrencyConflict::class,
                $translated
            );

            $this->assertSame($sqlState, $translated->sqlState);
            $this->assertSame($exception, $translated->getPrevious());
        }
    }

    public function test_pdo_exception_from_deferred_commit_is_translated(): void
    {
        $translator = new PostgresExceptionTranslator();

        $exception = new PDOException('deferred constraint failed');
        $exception->errorInfo = [
            'P0001',
            null,
            'deferred constraint failed',
        ];

        $translated = $translator->translate($exception);

        $this->assertInstanceOf(
            IntegrityConstraintViolation::class,
            $translated
        );

        $this->assertSame('P0001', $translated->sqlState);
        $this->assertSame($exception, $translated->getPrevious());
    }

    public function test_unknown_sqlstate_is_not_hidden(): void
    {
        $translator = new PostgresExceptionTranslator();
        $exception = $this->queryException('42601');

        $this->assertSame(
            $exception,
            $translator->translate($exception)
        );
    }

    private function queryException(string $sqlState): QueryException
    {
        $pdoException = new PDOException('database error');
        $pdoException->errorInfo = [
            $sqlState,
            null,
            'database error',
        ];

        return new QueryException(
            connectionName: 'pgsql',
            sql: 'select 1',
            bindings: [],
            previous: $pdoException,
        );
    }
}
