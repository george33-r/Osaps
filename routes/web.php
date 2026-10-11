<?php

use Illuminate\Support\Facades\Route;

/*
| The React SPA is built into public/spa (see web/vite.config.ts). Every non-API path
| returns its index.html and React Router takes over. Same origin, so Sanctum cookie
| sessions work without CORS.
*/
Route::get('/{any?}', function () {
    $index = public_path('spa/index.html');
    abort_unless(is_file($index), 503, 'Frontend not built. Run: npm --prefix web run build');

    return response()->file($index, ['Cache-Control' => 'no-cache']);
})->where('any', '^(?!api|sanctum|up|storage).*$');
