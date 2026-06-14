<?php

/*
|--------------------------------------------------------------------------
| Deploy artifacts sanity tests
|--------------------------------------------------------------------------
|
| Lightweight guard rails for the production deploy assets shipped under
| deploy/, .github/workflows/, and docs/deploy/. These do not exercise the
| application code path - they ensure the *deploy contract* doesn't drift:
|
|   - the cPanel .htaccess still routes the document root to public/index.php
|     AND still hard-denies sensitive files / directories. After the
|     2026-05-27 pivot, Laravel lives DIRECTLY in public_html/, so the
|     .htaccess is the only barrier preventing HTTP access to .env, app/,
|     vendor/, etc.;
|   - the post-deploy script has a shebang AND the executable bit so the
|     GitHub Actions runner can pipe it over SSH and bash will accept it;
|   - the workflow file no longer hardcodes the obsolete laravel-app path;
|   - the procedure document still mentions the right hostname / paths,
|     so on-call engineers don't follow a stale runbook.
|
| Kept intentionally narrow: each assertion catches one realistic
| regression and runs in milliseconds with no DB / HTTP dependencies.
*/

use Symfony\Component\Process\Process;

it('does not ship a stale cPanel bootstrap shim', function (): void {
    // The 2026-05-27 pivot moved Laravel directly into public_html/, so
    // the bootstrap shim is no longer needed. If it reappears, somebody
    // is re-introducing the laravel-app/ layout by mistake.
    expect(base_path('deploy/cpanel-bootstrap-index.php'))
        ->not->toBeFile(
            'deploy/cpanel-bootstrap-index.php must NOT exist after the '
            . 'public_html pivot. Laravel now lives directly in public_html/ '
            . 'and the front controller is public_html/public/index.php.'
        );
});

it('ships an executable post-deploy script with a bash shebang', function (): void {
    $script = base_path('deploy/post-deploy.sh');

    expect($script)->toBeFile();

    // Shebang check - bash, env-resolved so it works on every distro the
    // GH Actions runner may use.
    $firstLine = trim(fgets(fopen($script, 'r')) ?: '');
    expect($firstLine)->toStartWith('#!')
        ->and($firstLine)->toContain('bash');

    // Executable bit. On case-insensitive filesystems (macOS dev laptops)
    // git tracks the +x flag explicitly via core.fileMode, so this also
    // guards against an accidental `chmod -x` slipping into a commit.
    $perms = fileperms($script) & 0o777;
    expect($perms & 0o100)->not->toBe(
        0,
        sprintf('deploy/post-deploy.sh must be executable; got mode %o', $perms)
    );

    // The deploy script MUST run with strict error handling - `set -e`
    // (or equivalent) protects against half-applied deploys.
    $body = (string) file_get_contents($script);
    expect($body)->toMatch('/set\s+-[A-Za-z]*e/');

    // After the pivot the script must use $DEPLOY_PATH (the canonical
    // env var the workflow passes) and must point to public_html as the
    // default.
    expect($body)
        ->toContain('DEPLOY_PATH')
        ->toContain('/home/archivet/public_html')
        // Must NOT reference the obsolete /home/archivet/laravel-app
        // anywhere - that's the layout from the previous PR.
        ->not->toContain('/home/archivet/laravel-app');
});

it('verifies post-deploy.sh syntax with bash -n', function (): void {
    $script = base_path('deploy/post-deploy.sh');

    expect($script)->toBeFile();

    // `bash -n` parses the script without executing it - cheapest possible
    // syntax check that catches typos before they break a production deploy.
    $process = new Process(['bash', '-n', $script]);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue(
            'deploy/post-deploy.sh must pass `bash -n`. Output: '
            . $process->getOutput() . $process->getErrorOutput()
        );
});

it('documents the deploy procedure for the right host and paths', function (): void {
    $doc = base_path('docs/deploy/archivetool.md');

    expect($doc)->toBeFile();

    $body = (string) file_get_contents($doc);

    // The runbook must reference the exact SSH host and absolute paths the
    // automation relies on; a drift here is the #1 cause of failed manual
    // bootstraps.
    expect($body)
        ->toContain('cpanel19.vhosting-it.com')
        ->toContain('archivetool.eu')
        ->toContain('/home/archivet/public_html')
        ->toContain('SSH_PRIVATE_KEY_DEPLOY')
        ->toContain('deploy/post-deploy.sh')
        // Pivot documentation must explain the new structure and the
        // security trade-off it implies.
        ->toContain('public/index.php')
        ->toMatch('/(security|sensitive|critical|hard-?deny|trade-off|trade off)/i');
});

it('ships an .htaccess that routes to public/index.php and hard-denies sensitive paths', function (): void {
    $htaccess = base_path('deploy/cpanel-htaccess');

    expect($htaccess)->toBeFile();

    $body = (string) file_get_contents($htaccess);

    // (a) Routing: must enable rewrites AND forward to public/index.php
    // (the Laravel front controller, since the pivot moved Laravel
    // directly into public_html/).
    expect($body)
        ->toContain('RewriteEngine On')
        ->toMatch('/RewriteRule\s+\^\s+public\/index\.php\s+\[L\]/');

    // (b) Hard-deny of sensitive files / directories. Without these,
    // .env, vendor/, app/ etc. become reachable over HTTP.
    expect($body)
        // Dotfile blanket block
        ->toMatch('/<FilesMatch\s+"\^\\\\\."/')
        // 'Require all denied' at least once
        ->toMatch('/Require\s+all\s+denied/i')
        // RedirectMatch 404 on sensitive directories
        ->toMatch('/RedirectMatch\s+404/i')
        ->toContain('vendor')
        ->toContain('storage')
        ->toContain('app')
        // Composer + artisan must be explicitly blocked
        ->toContain('composer')
        ->toContain('artisan');
});

it('wires the deploy workflow with required secrets and no stale laravel-app refs', function (): void {
    $workflow = base_path('.github/workflows/deploy-archivetool.yml');

    expect($workflow)->toBeFile();

    $body = (string) file_get_contents($workflow);

    // The workflow must reference every secret documented in the runbook;
    // a missing reference here means CI will silently deploy without the
    // input it expects.
    expect($body)
        ->toContain('SSH_PRIVATE_KEY_DEPLOY')
        ->toContain('SSH_HOST')
        ->toContain('SSH_USER')
        ->toContain('DEPLOY_PATH')
        // Concurrency group prevents two deploys racing against the same
        // git working copy on the server.
        ->toContain('concurrency:')
        ->toContain('deploy-archivetool-production')
        // The repo guard makes sure a fork can't trigger a production deploy.
        ->toContain("github.repository == 'fabiodalez-dev/batch-list-tool'")
        // Smoke test must still hit the public-facing admin URL (unchanged
        // across the pivot since the URL shape is identical).
        ->toContain('archivetool.eu/admin/login')
        // The pivot retired the laravel-app/ layout. The workflow must NOT
        // hardcode that path anywhere - DEPLOY_PATH (from secrets) is now
        // the only source of truth.
        ->not->toContain('/home/archivet/laravel-app')
        ->not->toContain('laravel-app/');
});
