<?php

declare(strict_types=1);

use App\Application\Exceptions\IntegrityConstraintViolation;
use App\Application\Identity\ProvisionTeacher;
use App\Application\Identity\RedeemPasswordResetToken;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

if (($argv[1] ?? null) === null) {
    fwrite(
        STDERR,
        "Missing Phase G concurrency worker payload.\n"
    );

    exit(2);
}

try {
    $decoded = base64_decode(
        $argv[1],
        true,
    );

    if ($decoded === false) {
        throw new RuntimeException(
            'Invalid concurrency worker payload encoding.'
        );
    }

    $payload = json_decode(
        $decoded,
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($payload)) {
        throw new RuntimeException(
            'Invalid concurrency worker payload.'
        );
    }

    foreach ([
        'action',
        'ready_file',
        'go_file',
        'result_file',
    ] as $required) {
        if (
            ! isset($payload[$required])
            || ! is_string($payload[$required])
            || $payload[$required] === ''
        ) {
            throw new RuntimeException(
                "Missing worker field: {$required}"
            );
        }
    }

    $identity = DB::selectOne(
        <<<'SQL'
SELECT
    current_database() AS database_name,
    current_user AS database_user,
    pg_backend_pid() AS backend_pid
SQL
    );

    if (
        $identity === null
        || $identity->database_name
            !== 'sewaellf_educore_test'
        || $identity->database_user
            !== 'sewaellf_educore_Admin'
    ) {
        throw new RuntimeException(
            'Phase G worker refused non-dedicated '
            .'PostgreSQL identity.'
        );
    }

    $writeJson = static function (
        string $path,
        array $value,
    ): void {
        $temporary =
            $path.'.tmp.'.getmypid();

        $bytes = file_put_contents(
            $temporary,
            json_encode(
                $value,
                JSON_THROW_ON_ERROR,
            ),
            LOCK_EX,
        );

        if ($bytes === false) {
            throw new RuntimeException(
                "Unable to write worker signal: {$path}"
            );
        }

        if (! rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(
                "Unable to publish worker signal: {$path}"
            );
        }
    };

    $writeJson(
        $payload['ready_file'],
        [
            'pid' => (int) $identity->backend_pid,
            'database' => (string) $identity->database_name,
            'user' => (string) $identity->database_user,
        ],
    );

    $deadline = microtime(true) + 8.0;

    while (! is_file($payload['go_file'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException(
                'Timed out waiting for GO barrier.'
            );
        }

        usleep(10_000);
    }

    try {
        $data = match ($payload['action']) {
            'provision_teacher' => (static function () use (
                $payload
            ): array {
                $teacher = app(
                    ProvisionTeacher::class
                )->execute(
                    actorUserId: $payload['actor_user_id'],
                    name: $payload['name'],
                    email: $payload['email'],
                );

                return [
                    'id' => $teacher->id,
                    'email' => $teacher->email,
                    'role' => $teacher->role,
                    'status' => $teacher->status,
                ];
            })(),

            'redeem_password_reset_token' => (static function () use (
                $payload
            ): array {
                $user = app(
                    RedeemPasswordResetToken::class
                )->execute(
                    email: $payload['email'],
                    token: $payload['token'],
                    password: $payload['password'],
                );

                return [
                    'id' => $user->id,
                    'status' => $user->status,
                ];
            })(),

            default => throw new RuntimeException(
                'Unknown Phase G concurrency action: '
                .$payload['action']
            ),
        };

        $writeJson(
            $payload['result_file'],
            [
                'result' => 'success',
                'action' => $payload['action'],
                'data' => $data,
            ],
        );
    } catch (Throwable $exception) {
        $sqlState = null;

        $previous = $exception->getPrevious();

        if (
            $previous
                instanceof IntegrityConstraintViolation
        ) {
            $sqlState =
                $previous->sqlState;
        }

        $writeJson(
            $payload['result_file'],
            [
                'result' => 'exception',
                'action' => $payload['action'],
                'class' => $exception::class,
                'message' => $exception->getMessage(),
                'sql_state' => $sqlState,
            ],
        );
    }
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        $exception::class
        .': '
        .$exception->getMessage()
        .PHP_EOL,
    );

    exit(1);
}
