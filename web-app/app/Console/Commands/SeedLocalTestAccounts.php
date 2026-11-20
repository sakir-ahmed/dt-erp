<?php

namespace App\Console\Commands;

use App\Actions\LocalQuickSignIn;
use Database\Seeders\LocalQuickSignInSeeder;
use Illuminate\Console\Command;

class SeedLocalTestAccounts extends Command
{
    protected $signature = 'erp:test-accounts:seed {--refresh : Refresh existing fixtures using their previous application key}';

    protected $description = 'Seed or refresh local quick-sign-in test accounts';

    public function handle(LocalQuickSignInSeeder $seeder): int
    {
        if (! LocalQuickSignInSeeder::canSeed()) {
            $this->error('This command requires a local environment, debug mode, a loopback APP_URL, and an application key.');

            return self::FAILURE;
        }

        $previousKey = null;

        if ($this->option('refresh')) {
            if (! $this->input->isInteractive()) {
                $this->error('Run the refresh in an interactive terminal.');

                return self::FAILURE;
            }

            $previousKey = $this->secret('Previous APP_KEY used to seed the fixtures', false);

            if (blank($previousKey)) {
                $this->error('A previous application key is required.');

                return self::FAILURE;
            }
        }

        $seeder->setContainer($this->laravel)->setCommand($this)(['previousKey' => $previousKey]);

        $eligible = LocalQuickSignIn::eligibleAccounts()->count();
        $this->info("Eligible local test accounts: {$eligible}.");

        return $eligible > 0 ? self::SUCCESS : self::FAILURE;
    }
}
