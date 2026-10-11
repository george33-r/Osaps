<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
| One-time installer for hosting without SSH (e.g. Hostinger shared hosting).
| Visit /install/{INSTALL_TOKEN} once to create the database tables.
| Add ?demo=1 to load clearly-labelled demonstration data (refused when APP_ENV=production).
| Running it again later applies new migrations (upgrade) without touching data.
| Remove INSTALL_TOKEN from .env whenever you are not installing or upgrading.
*/
Route::get('/install/{token}', function (string $token) {
    $expected = (string) env('INSTALL_TOKEN', '');
    abort_if(strlen($expected) < 24 || ! hash_equals($expected, $token), 404);

    // First run creates the tables (and optional demo data). Later runs only apply new migrations,
    // so the same link upgrades an existing site without touching its data.
    $lock = storage_path('app/installed.lock');
    $upgrading = is_file($lock);

    $log = [];
    try {
        // New config files (e.g. config/ai.php) are ignored while an old config cache exists.
        Artisan::call('optimize:clear');
        Artisan::call('migrate', ['--force' => true]);
        $log[] = trim(Artisan::output()) ?: 'Database already up to date.';

        if (! $upgrading && request()->boolean('demo')) {
            if (app()->isProduction()) {
                $log[] = 'Demo data skipped: APP_ENV is production. Set APP_ENV=staging for a demo site.';
            } else {
                Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
                $log[] = trim(Artisan::output()) ?: 'Demo data loaded (password for all demo accounts: demo-pass-123).';
            }
        } elseif ($upgrading && request()->boolean('demo') && ! app()->isProduction()) {
            // Refresh demo records (e.g. new demo vendor details) without touching real data.
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $log[] = 'Demo data refreshed.';
        }

        file_put_contents($lock, ($upgrading ? file_get_contents($lock).' | upgraded ' : '').now()->toDateTimeString());
        $log[] = ($upgrading ? 'Upgrade done.' : 'Install done.').' Remove INSTALL_TOKEN from .env now.';

        return response(implode("\n\n", array_filter($log)), 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    } catch (Throwable $e) {
        report($e);

        return response("Install failed:\n\n".$e->getMessage()."\n\nCheck the DB_* values in .env.", 500)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
})->where('token', '[A-Za-z0-9]+');

/*
| The React SPA is built into public/spa (see web/vite.config.ts). Every non-API path
| returns its index.html and React Router takes over. Same origin, so Sanctum cookie
| sessions work without CORS.
*/
Route::get('/{any?}', function () {
    $index = public_path('spa/index.html');
    abort_unless(is_file($index), 503, 'Frontend not built. Run: npm --prefix web run build');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api|sanctum|up|storage|install).*$');
