<?php

/*
|--------------------------------------------------------------------------
| cPanel bootstrap shim for /home/archivet/public_html/index.php
|--------------------------------------------------------------------------
|
| cPanel shared hosting (CloudLinux EL8, archivetool.eu) serves files from
| /home/archivet/public_html/ as the document root. Laravel expects to be
| served from its own /public/ subdirectory while keeping app/, vendor/,
| storage/ and .env OUTSIDE the public web root.
|
| Layout on the server after first-time deploy:
|
|   /home/archivet/
|   |-- laravel-app/                <-- git clone of this repository
|   |   |-- app/
|   |   |-- bootstrap/
|   |   |-- config/
|   |   |-- public/                 <-- real Laravel public/ directory
|   |   |   |-- index.php           <-- real Laravel entrypoint
|   |   |   `-- .htaccess
|   |   |-- storage/
|   |   |-- vendor/
|   |   `-- .env                    <-- secrets, NEVER inside public_html
|   `-- public_html/                <-- cPanel document root
|       |-- index.php               <-- THIS FILE (copy of cpanel-bootstrap-index.php)
|       |-- .htaccess               <-- copy of deploy/cpanel-htaccess
|       `-- storage -> ../laravel-app/storage/app/public  (symlink, public assets)
|
| Why a shim instead of a symlink?
| ---------------------------------
| Some cPanel setups silently break PHP symlinks (open_basedir, AccelerateCgi,
| or SuPHP wrappers refusing to follow symlinks across home subtrees). A tiny
| bootstrap file is bulletproof: it just rewrites the request's working
| directory to the real Laravel public/ and includes the real index.php.
|
| Two environment hints are exported so Laravel sees the correct public path
| and so any code inspecting $_SERVER['DOCUMENT_ROOT'] keeps working.
*/

declare(strict_types=1);

// Absolute path to the Laravel application root (one level above public_html).
$laravelRoot = dirname(__DIR__) . '/laravel-app';
$laravelPublic = $laravelRoot . '/public';
$laravelEntry = $laravelPublic . '/index.php';

if (! is_file($laravelEntry)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Application offline.\n";
    echo "Laravel entrypoint not found at: {$laravelEntry}\n";
    exit;
}

// Pretend the document root is the real Laravel public/ directory so that
// Laravel-internal path resolution (asset URLs, public_path()) stays correct.
$_SERVER['DOCUMENT_ROOT'] = $laravelPublic;
$_SERVER['SCRIPT_FILENAME'] = $laravelEntry;
$_SERVER['SCRIPT_NAME'] = '/index.php';

chdir($laravelPublic);

require $laravelEntry;
