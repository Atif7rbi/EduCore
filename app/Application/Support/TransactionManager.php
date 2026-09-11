<?php

namespace App\Application\Support;

use App\Infrastructure\Database\PostgresExceptionTranslator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

class TransactionManager
{
    public function __construct(
        private readonly PostgresExceptionTranslator $translator,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        try {
            return DB::transaction(
                callback: $callback,
                attempts: 1,
            );
        } catch (QueryException|PDOException $exception) {
            throw $this->translator->translate($exception);
        }
    }
}
