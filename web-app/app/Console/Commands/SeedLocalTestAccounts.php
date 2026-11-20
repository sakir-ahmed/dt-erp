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
        $refresh = (bool) $this->option('refresh');

        if (! LocalQuickSignInSeeder::canSeed($refresh)) {
            $this->error('Use local quick sign-in, or enable ERP demo login and pass --refresh. An application key is required.');

            return self::FAILURE;
        }

        $previousKey = null;

        if ($refresh) {
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
