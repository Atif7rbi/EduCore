<?php

namespace App\Console\Commands;

use App\Application\LocalDemo\BootstrapLocalDemo;
use App\Application\LocalDemo\LocalDemoEnvironmentGuard;
use Illuminate\Console\Command;
use Throwable;

class BootstrapLocalDemoCommand extends Command
{
    protected $signature = 'educore:bootstrap-local-demo';

    protected $description = 'Create the guarded local-only EduCore demonstration dataset';

    public function handle(
        LocalDemoEnvironmentGuard $environment,
        BootstrapLocalDemo $bootstrap,
    ): int {
        try {
            $environment->assertSafe();
        } catch (Throwable) {
            $this->components->error('Local demo bootstrap refused: the environment safety contract failed.');

            return self::FAILURE;
        }

        $password = $this->secret('Demo account password');
        $confirmation = $this->secret('Confirm demo account password');

        if (
            ! is_string($password)
            || mb_strlen($password) < 12
            || ! hash_equals($password, (string) $confirmation)
        ) {
            $this->components->error('Local demo bootstrap refused: invalid password confirmation.');

            return self::FAILURE;
        }

        try {
            $bootstrap->execute($password);
        } catch (Throwable) {
            $this->components->error('Local demo bootstrap failed safely; no demo data was committed.');

            return self::FAILURE;
        }

        $this->components->info('Local demo data created successfully.');

        return self::SUCCESS;
    }
}
