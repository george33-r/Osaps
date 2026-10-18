<?php

namespace App\Http\Controllers;

use App\Domain\Support\InstallLedger;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Web installer for hosting without SSH (Hostinger shared hosting).
 *
 * Token lifecycle:
 *  - The owner puts a fresh INSTALL_TOKEN (32+ letters and digits) in .env.
 *  - Visiting /install/{token} runs migrations (and optional demo data) once.
 *  - The token's hash is then recorded in storage/app/installed.lock and that token never works again,
 *    even if it is left in .env. A later upgrade needs a new token.
 */
class InstallController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        $expected = (string) env('INSTALL_TOKEN', '');
        abort_unless(InstallLedger::tokenIsStrong($expected) && hash_equals($expected, $token), 404);

        // A copied site can be missing empty folders; create the ones Laravel writes to.
        foreach (['app', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
            if (! is_dir(storage_path($dir))) {
                @mkdir(storage_path($dir), 0755, true);
            }
        }
        // Keep the record in storage/app; if that folder cannot be created, use storage/framework,
        // which the front controller already checks is writable.
        $lockPath = is_dir(storage_path('app')) && is_writable(storage_path('app'))
            ? storage_path('app/installed.lock')
            : storage_path('framework/installed.lock');
        if (! is_file($lockPath) && is_file(storage_path('framework/installed.lock'))) {
            $lockPath = storage_path('framework/installed.lock');
        }
        $ledger = InstallLedger::fromLock(is_file($lockPath) ? (string) file_get_contents($lockPath) : null);
        if ($ledger->wasUsed($token)) {
            return $this->text("This install link has already been used.\n\nTo run it again, put a new INSTALL_TOKEN in .env, visit the new link once, then delete the line.", 410);
        }

        $upgrading = $ledger->installed;
        $log = [];
        try {
            // New config files (e.g. config/ai.php) are ignored while an old config cache exists.
            Artisan::call('optimize:clear');
            Artisan::call('migrate', ['--force' => true]);
            $log[] = trim(Artisan::output()) ?: 'Database already up to date.';

            if ($request->boolean('demo')) {
                if (app()->isProduction()) {
                    $log[] = 'Demo data skipped: APP_ENV is production.';
                } else {
                    Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
                    $log[] = trim(Artisan::output()) ?: 'Demo data loaded.';
                }
            }

            // The OSAPS store's own fabrics (database/catalogue). Safe on the live site: adds what is missing only.
            // Runs on every install: it only adds what is missing and re-checks the store is listed.
            {
                $seeder = app(\Database\Seeders\StoreCatalogueSeeder::class);
                $seeder->run();
                $log = array_merge($log, $seeder->report);
            }

            file_put_contents($lockPath, $ledger->record($token, now()->toDateTimeString(), $upgrading ? 'upgrade' : 'install')->toLock(), LOCK_EX);
            $log[] = '(installer v5)';
            $log[] = ($upgrading ? 'Upgrade done.' : 'Install done.').' This link will not work again. Delete the INSTALL_TOKEN line from .env now.';

            return $this->text(implode("\n\n", array_filter($log)));
        } catch (Throwable $e) {
            report($e);

            // The full error is in storage/logs; only its first line is shown, to keep connection details off the screen.
            $first = strtok($e->getMessage(), "\n") ?: 'Unknown error';

            return $this->text("Install failed: ".mb_substr(preg_replace('/\(Connection:.*$/', '', $first), 0, 300)."\n\nCheck the DB_* values in .env. Details are in storage/logs/laravel.log.", 500);
        }
    }

    private function text(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
