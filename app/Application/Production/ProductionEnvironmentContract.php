<?php

namespace App\Application\Production;

use RuntimeException;

class ProductionEnvironmentContract
{
    /**
     * Validate configuration required for a production runtime.
     *
     * This contract intentionally validates configuration values,
     * not raw env() calls, so it remains compatible with config:cache.
     */
    public function validate(): void
    {
        $violations = [];

        if (config('app.debug') !== false) {
            $violations[] =
                'APP_DEBUG must be false in production.';
        }

        $appUrl = (string) config('app.url');

        if (! str_starts_with($appUrl, 'https://')) {
            $violations[] =
                'APP_URL must use HTTPS in production.';
        }

        if (config('database.default') !== 'pgsql') {
            $violations[] =
                'DB_CONNECTION must be pgsql in production.';
        }

        $sessionDriver =
            (string) config('session.driver');

        if (
            ! in_array(
                $sessionDriver,
                ['database', 'redis'],
                true,
            )
        ) {
            $violations[] =
                'SESSION_DRIVER must be database or redis in production.';
        }

        if (config('session.secure') !== true) {
            $violations[] =
                'SESSION_SECURE_COOKIE must be true in production.';
        }

        if (config('session.http_only') !== true) {
            $violations[] =
                'SESSION_HTTP_ONLY must be true in production.';
        }

        $sameSite =
            (string) config('session.same_site');

        if (
            ! in_array(
                $sameSite,
                ['lax', 'strict'],
                true,
            )
        ) {
            $violations[] =
                'SESSION_SAME_SITE must be lax or strict in production.';
        }

        $cacheStore =
            (string) config('cache.default');

        if (
            ! in_array(
                $cacheStore,
                ['database', 'redis'],
                true,
            )
        ) {
            $violations[] =
                'CACHE_STORE must be database or redis in production.';
        }

        $queueConnection =
            (string) config('queue.default');

        if (
            ! in_array(
                $queueConnection,
                ['database', 'redis'],
                true,
            )
        ) {
            $violations[] =
                'QUEUE_CONNECTION must be database or redis in production.';
        }

        if ($violations !== []) {
            throw new RuntimeException(
                "Unsafe EduCore production configuration:\n- "
                .implode(
                    "\n- ",
                    $violations,
                )
            );
        }
    }
}
