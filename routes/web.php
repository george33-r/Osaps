<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
| One-time installer for hosting without SSH (e.g. Hostinger shared hosting).
| Visit /install/{INSTALL_TOKEN} once to create the database tables.
| Add ?demo=1 to load clearly-labelled demonstration data (refused when APP_ENV=production).
| It locks itself after a successful run; delete storage/app/installed.lock to run it again,
| and remove INSTALL_TOKEN from .env when you are done.
*/
Route::get('/install/{token}', function (string $token) {
    $expected = (string) env('INSTALL_TOKEN', '');
    abort_if(strlen($expected) < 24 || ! hash_equals($expected, $token), 404);

    $lock = storage_path('app/installed.lock');
    if (is_file($lock)) {
        return response('Already installed on '.file_get_contents($lock).'. Delete storage/app/installed.lock to run again.', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    $log = [];
    try {
        Artisan::call('migrate', ['--force' => true]);
        $log[] = trim(Artisan::output());

        if (request()->boolean('demo')) {
            if (app()->isProduction()) {
                $log[] = 'Demo data skipped: APP_ENV is production. Set APP_ENV=staging for a demo site.';
            } else {
                Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
                $log[] = trim(Artisan::output()) ?: 'Demo data loaded (password for all demo accounts: demo-pass-123).';
            }
        }

        file_put_contents($lock, now()->toDateTimeString());
        $log[] = 'Done. Remove INSTALL_TOKEN from .env now.';

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
