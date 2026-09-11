<?php

namespace App\Console\Commands;

use App\Application\Production\ProductionEnvironmentContract;
use App\Application\Production\ProductionSmokeCheck;
use Illuminate\Console\Command;
use RuntimeException;

class ProductionSmokeCheckCommand extends Command
{
    protected $signature =
        'production:smoke-check';

    protected $description =
        'Run EduCore post-deployment smoke checks';

    public function handle(
        ProductionSmokeCheck $smoke,
        ProductionEnvironmentContract $environment,
    ): int {
        try {
            if (app()->environment('production')) {
                $environment->validate();
            }

            $result =
                $smoke->inspect();
        } catch (RuntimeException $exception) {
            $this->error(
                'EduCore smoke check FAILED'
            );

            $this->error(
                $exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->info(
            'EduCore smoke check: OK'
        );

        foreach ($result as $name => $value) {
            $rendered =
                is_bool($value)
                    ? (
                        $value
                            ? 'OK'
                            : 'FAIL'
                    )
                    : (string) $value;

            $this->line(
                $name
                .': '
                .$rendered
            );
        }

        return self::SUCCESS;
    }
}
