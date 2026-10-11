<?php

/*
| OSAPS front controller for Hostinger shared hosting.
| Works with either layout:
|   A) domains/YOUR-DOMAIN/osaps/ + domains/YOUR-DOMAIN/public_html/  (app beside the web root)
|   B) public_html/SUBDOMAIN/ holding the app, with this file in public_html/SUBDOMAIN/public_html/
|      (needs the root .htaccess from deploy/hostinger/root.htaccess one level up)
*/

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Find the app: the first candidate folder that contains Laravel's "artisan" file.
$base = null;
foreach ([dirname(__DIR__).'/osaps', dirname(__DIR__).'/asola', dirname(__DIR__)] as $candidate) {
    if (is_file($candidate.'/artisan') && is_dir($candidate.'/app')) {
        $base = $candidate;
        break;
    }
}

// Plain-language checks so a missing step shows a message instead of a blank page.
$problems = [];
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    $problems[] = 'PHP '.PHP_VERSION.' is too old. In hPanel go to Advanced → PHP Configuration and choose PHP 8.3.';
}
if ($base === null) {
    $problems[] = 'The app folder was not found. Expected the folder containing "artisan" and "app" one level above: '.dirname(__DIR__);
} elseif (! is_file($base.'/vendor/autoload.php')) {
    $problems[] = 'The "vendor" folder is missing from '.$base.'. Upload it (see DEPLOY.md, step 2).';
} elseif (! is_file($base.'/.env')) {
    $problems[] = 'The settings file '.$base.'/.env is missing. File Manager may hide files starting with a dot: enable "Show hidden files".';
} else {
    foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
        if (! is_dir($base.'/'.$dir)) {
            @mkdir($base.'/'.$dir, 0755, true);
        }
        if (! is_writable($base.'/'.$dir)) {
            $problems[] = 'The folder '.$dir.' is not writable. In File Manager set its permissions to 755.';
        }
    }
}
if ($problems) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Setup not finished</title>'
        .'<body style="font:16px/1.5 system-ui;max-width:40rem;margin:3rem auto;padding:0 1rem">'
        .'<h1 style="font-size:1.4rem">OSAPS setup is not finished yet</h1><ul><li>'
        .implode('</li><li>', array_map('htmlspecialchars', $problems)).'</li></ul></body>';
    exit;
}

if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $base.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once $base.'/bootstrap/app.php';

// Laravel's public folder is this folder, not <app>/public.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
