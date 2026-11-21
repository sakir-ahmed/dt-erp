<?php

use Dotenv\Dotenv;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string SECTION = 'ai_provider_credentials';

    public function up(): void
    {
        $testing = app()->runningUnitTests();
        $environment = $testing ? [] : Dotenv::createArrayBacked(app()->environmentPath(), app()->environmentFile())->safeLoad();

        DB::transaction(function () use ($environment, $testing): void {
            $legacy = DB::table('cv_ai_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
            foreach ([
                'openrouter' => 'CONVERSE_OPENROUTER_API_KEY',
                'openai' => 'CONVERSE_OPENAI_API_KEY',
                'deepseek' => 'CONVERSE_DEEPSEEK_API_KEY',
                'anthropic' => 'CONVERSE_ANTHROPIC_API_KEY',
            ] as $provider => $variable) {
                $key = $testing ? null : (Env::get($variable) ?? $environment[$variable] ?? null);
                $encrypted = match (true) {
                    $provider === 'openai' && filled($legacy->openai_key) => $legacy->openai_key,
                    $provider === 'openai' && $legacy->openai_key_removed => null,
                    is_string($key) && trim($key) !== '' => Crypt::encryptString($key),
                    default => null,
                };
                if ($encrypted !== null) {
                    DB::table('settings')->insertOrIgnore([
                        'section' => self::SECTION, 'key' => $provider,
                        'value' => json_encode($encrypted, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            DB::table('cv_ai_settings')->where('id', 1)->update(['openai_key' => null]);
        });
    }

    public function down(): void
    {
        $setting = DB::table('settings')->where(['section' => self::SECTION, 'key' => 'openai'])->first();
        if ($setting !== null) {
            $encrypted = json_decode($setting->value ?? 'null', true, flags: JSON_THROW_ON_ERROR);
            DB::table('cv_ai_settings')->where('id', 1)->update([
                'openai_key' => $encrypted, 'openai_key_removed' => $encrypted === null,
            ]);
        }
    }
};
