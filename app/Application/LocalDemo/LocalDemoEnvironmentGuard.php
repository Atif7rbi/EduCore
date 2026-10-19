<?php

namespace App\Application\LocalDemo;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalDemoEnvironmentGuard
{
    public const DATABASE = 'educore_local_dev';

    public const ROLE = 'educore_local_dev_role';

    public function assertSafe(): void
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (
            ! app()->environment('local')
            || ! is_array($connection)
            || ($connection['driver'] ?? null) !== 'pgsql'
            || ($connection['host'] ?? null) !== '127.0.0.1'
            || (string) ($connection['port'] ?? '') !== '5432'
            || ($connection['database'] ?? null) !== self::DATABASE
            || ($connection['username'] ?? null) !== self::ROLE
            || $this->hasDatabaseUrlOverride($connection)
        ) {
            throw new RuntimeException('The local demo bootstrap environment is not safe.');
        }

        $this->assertLiveIdentity(DB::connection($connectionName));
    }

    /** @param array<string, mixed> $connection */
    private function hasDatabaseUrlOverride(array $connection): bool
    {
        $values = [
            $connection['url'] ?? null,
            $_ENV['DB_URL'] ?? null,
            $_SERVER['DB_URL'] ?? null,
            getenv('DB_URL'),
        ];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function assertLiveIdentity(ConnectionInterface $connection): void
    {
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name,
                   current_user AS role_name,
                   host(inet_server_addr()) AS server_address,
                   inet_server_port() AS server_port
            SQL);

        if (
            $identity === null
            || $identity->database_name !== self::DATABASE
            || $identity->role_name !== self::ROLE
            || $identity->server_address !== '127.0.0.1'
            || (int) $identity->server_port !== 5432
        ) {
            throw new RuntimeException('The local demo bootstrap database identity is not safe.');
        }
    }
}
