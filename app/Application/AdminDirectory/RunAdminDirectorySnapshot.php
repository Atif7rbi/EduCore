<?php

namespace App\Application\AdminDirectory;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RunAdminDirectorySnapshot
{
    public function execute(Closure $read): mixed
    {
        $connection = DB::connection();

        if ($connection->transactionLevel() > 0) {
            $this->assertCompatibleTransaction(
                $connection
            );

            return $read($connection);
        }

        return $connection->transaction(
            function () use (
                $connection,
                $read,
            ): mixed {
                $connection->statement(
                    <<<'SQL'
SET TRANSACTION
ISOLATION LEVEL REPEATABLE READ,
READ ONLY
SQL
                );

                $this->assertCompatibleTransaction(
                    $connection
                );

                return $read($connection);
            }
        );
    }

    private function assertCompatibleTransaction(
        Connection $connection,
    ): void {
        $state = $connection->selectOne(
            <<<'SQL'
SELECT
    current_setting(
        'transaction_isolation'
    ) AS isolation_level,
    current_setting(
        'transaction_read_only'
    ) AS read_only
SQL
        );

        if (
            ($state->isolation_level ?? null)
                !== 'repeatable read'
            || ($state->read_only ?? null)
                !== 'on'
        ) {
            throw new RuntimeException(
                'Admin directory reads require a '
                .'READ ONLY REPEATABLE READ transaction.'
            );
        }
    }
}
