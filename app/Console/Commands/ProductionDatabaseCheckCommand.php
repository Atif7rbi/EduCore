<?php

namespace App\Console\Commands;

use App\Application\Production\DatabaseReadinessCheck;
use Illuminate\Console\Command;
use RuntimeException;

class ProductionDatabaseCheckCommand extends Command
{
    protected $signature =
        'production:database-check';

    protected $description =
        'Verify EduCore production database readiness';

    public function handle(
        DatabaseReadinessCheck $check,
    ): int {
        try {
            $result = $check->inspect();
        } catch (RuntimeException $exception) {
            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->info(
            'EduCore database readiness: OK'
        );

        $this->line(
            'Driver: '
            .$result['driver']
        );

        $this->line(
            'PostgreSQL: '
            .$result['server_version']
        );

        $this->line(
            'Minimum supported PostgreSQL: '
            .$result[
                'minimum_supported_version'
            ]
        );

        $this->line(
            'Pending migrations: '
            .(
                $result['migrations_pending']
                    ? 'YES'
                    : 'NO'
            )
        );

        if ($result['migrations_pending']) {
            $this->warn(
                'Pending migrations must be reviewed before production deployment.'
            );
        }

        return self::SUCCESS;
    }
}
