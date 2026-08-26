<?php

namespace App\Console\Commands;

use App\Application\Production\ProductionReadinessAudit;
use Illuminate\Console\Command;

class ProductionReadinessAuditCommand extends Command
{
    protected $signature =
        'production:readiness-audit';

    protected $description =
        'Run the final EduCore production readiness audit';

    public function handle(
        ProductionReadinessAudit $audit,
    ): int {
        $result =
            $audit->inspect();

        $this->line(
            'EduCore Production Readiness Audit'
        );

        $this->newLine();

        $this->line(
            'Implementation ready: '
            .(
                $result[
                    'implementation_ready'
                ]
                    ? 'YES'
                    : 'NO'
            )
        );

        $this->line(
            'Deployment ready: '
            .(
                $result[
                    'deployment_ready'
                ]
                    ? 'YES'
                    : 'NO'
            )
        );

        $this->line(
            'Environment: '
            .$result[
                'environment'
            ][
                'app_environment'
            ]
        );

        $this->line(
            'Database: '
            .(
                $result[
                    'database'
                ]['ok']
                    ? 'OK'
                    : 'BLOCKED'
            )
        );

        if (
            $result[
                'database'
            ][
                'server_version'
            ] !== null
        ) {
            $this->line(
                'PostgreSQL: '
                .$result[
                    'database'
                ][
                    'server_version'
                ]
            );
        }

        $this->line(
            'Minimum PostgreSQL major: '
            .$result[
                'database'
            ][
                'minimum_supported_major'
            ]
        );

        $this->line(
            'Runtime smoke: '
            .(
                $result[
                    'smoke'
                ]['ok']
                    ? 'OK'
                    : 'FAILED'
            )
        );

        if (
            $result['blockers']
            !== []
        ) {
            $this->newLine();

            $this->warn(
                'Deployment blockers:'
            );

            foreach (
                $result['blockers']
                as $blocker
            ) {
                $this->line(
                    '- '.$blocker
                );
            }
        }

        return $result[
            'deployment_ready'
        ]
            ? self::SUCCESS
            : self::FAILURE;
    }
}
