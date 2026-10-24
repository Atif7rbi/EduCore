<?php

namespace Tests\Support;

use JsonException;
use RuntimeException;

final class ParentLockReadyArtifact
{
    /**
     * @param  array{pid: int, database: string, user: string}  $payload
     */
    public static function publish(
        string $path,
        array $payload,
    ): void {
        $normalized = self::validatePayload($payload);
        $encoded = json_encode(
            $normalized,
            JSON_THROW_ON_ERROR,
        );

        self::decode($encoded);

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new RuntimeException(
                'READY artifact directory does not exist.',
            );
        }

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException(
                'READY artifact already exists.',
            );
        }

        $temporaryPath = $path
            .'.tmp.'
            .getmypid()
            .'.'
            .bin2hex(random_bytes(8));
        $handle = @fopen($temporaryPath, 'xb');

        if (! is_resource($handle)) {
            throw new RuntimeException(
                'Unable to create temporary READY artifact.',
            );
        }

        $published = false;

        try {
            $length = strlen($encoded);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite(
                    $handle,
                    substr($encoded, $offset),
                );

                if ($written === false || $written === 0) {
                    throw new RuntimeException(
                        'Unable to write complete READY artifact.',
                    );
                }

                $offset += $written;
            }

            if (! fflush($handle)) {
                throw new RuntimeException(
                    'Unable to flush READY artifact.',
                );
            }

            if (
                function_exists('fsync')
                && ! fsync($handle)
            ) {
                throw new RuntimeException(
                    'Unable to synchronize READY artifact.',
                );
            }

            if (! fclose($handle)) {
                throw new RuntimeException(
                    'Unable to close READY artifact.',
                );
            }

            $handle = null;

            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException(
                    'READY artifact appeared before publication.',
                );
            }

            if (! @rename($temporaryPath, $path)) {
                throw new RuntimeException(
                    'Unable to publish READY artifact atomically.',
                );
            }

            $published = true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (! $published && file_exists($temporaryPath)) {
                if (! @unlink($temporaryPath)) {
                    throw new RuntimeException(
                        'Unable to clean temporary READY artifact.',
                    );
                }
            }
        }
    }

    /**
     * @param  callable(): bool  $childIsRunning
     * @return array{pid: int, database: string, user: string}
     */
    public static function waitForValid(
        string $path,
        callable $childIsRunning,
        float $timeoutSeconds = 8.0,
        int $pollIntervalMicroseconds = 10_000,
    ): array {
        if ($timeoutSeconds <= 0) {
            throw new RuntimeException(
                'READY timeout must be positive.',
            );
        }

        if ($pollIntervalMicroseconds < 0) {
            throw new RuntimeException(
                'READY poll interval cannot be negative.',
            );
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $lastValidationError = null;

        do {
            if (is_file($path)) {
                try {
                    return self::read($path);
                } catch (RuntimeException $exception) {
                    $lastValidationError =
                        $exception->getMessage();
                }
            }

            if (! $childIsRunning()) {
                throw new RuntimeException(
                    'Child exited before a valid READY artifact '
                    .'was published.'
                    .self::diagnosticSuffix(
                        $lastValidationError,
                    ),
                );
            }

            if ($pollIntervalMicroseconds > 0) {
                usleep($pollIntervalMicroseconds);
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'Timed out waiting for a valid READY artifact.'
            .self::diagnosticSuffix(
                $lastValidationError,
            ),
        );
    }

    /**
     * @return array{pid: int, database: string, user: string}
     */
    public static function read(string $path): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(
                'READY artifact could not be read.',
            );
        }

        return self::decode($contents);
    }

    /**
     * @return array{pid: int, database: string, user: string}
     */
    private static function decode(string $contents): array
    {
        try {
            $decoded = json_decode(
                $contents,
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'READY artifact JSON is incomplete or malformed.',
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(
                'READY artifact payload must be an object.',
            );
        }

        return self::validatePayload($decoded);
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{pid: int, database: string, user: string}
     */
    private static function validatePayload(array $payload): array
    {
        $pid = $payload['pid'] ?? null;
        $database = $payload['database'] ?? null;
        $user = $payload['user'] ?? null;

        if (! is_int($pid) || $pid <= 0) {
            throw new RuntimeException(
                'READY artifact PID is invalid.',
            );
        }

        if (! is_string($database) || $database === '') {
            throw new RuntimeException(
                'READY artifact database identity is invalid.',
            );
        }

        if (! is_string($user) || $user === '') {
            throw new RuntimeException(
                'READY artifact role identity is invalid.',
            );
        }

        return [
            'pid' => $pid,
            'database' => $database,
            'user' => $user,
        ];
    }

    private static function diagnosticSuffix(
        ?string $lastValidationError,
    ): string {
        return $lastValidationError === null
            ? ''
            : ' Last validation error: '
                .$lastValidationError;
    }
}
