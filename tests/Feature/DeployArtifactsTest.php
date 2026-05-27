<?php

/*
|--------------------------------------------------------------------------
| Deploy artifacts sanity tests
|--------------------------------------------------------------------------
|
| Lightweight guard rails for the production deploy assets shipped under
| deploy/, .github/workflows/, and docs/deploy/. These do not exercise the
| application code path — they ensure the *deploy contract* doesn't drift:
|
|   - the cPanel bootstrap shim is syntactically valid PHP, otherwise the
|     site 500s on the first request after first-time bootstrap;
|   - the post-deploy script has a shebang AND the executable bit so the
|     GitHub Actions runner can pipe it over SSH and bash will accept it;
|   - the .htaccess shipped for cPanel still rewrites to the shim, so we
|     don't accidentally publish a rule set that exposes /vendor;
|   - the procedure document still mentions the right hostname / paths,
|     so on-call engineers don't follow a stale runbook.
|
| Kept intentionally narrow: each assertion catches one realistic
| regression and runs in milliseconds with no DB / HTTP dependencies.
*/

use Symfony\Component\Process\Process;

it('ships a syntactically valid cPanel bootstrap shim', function (): void {
    $shim = base_path('deploy/cpanel-bootstrap-index.php');

    expect($shim)->toBeFile();

    // `php -l` is the cheapest possible syntax check. We invoke it via
    // Symfony Process so we don't depend on shell_exec being enabled in
    // the test runner's php.ini.
    $process = new Process([PHP_BINARY, '-l', $shim]);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue(
            'deploy/cpanel-bootstrap-index.php must lint with `php -l`. '
            . 'Output: ' . $process->getOutput() . $process->getErrorOutput()
        );

    // Sanity: the shim must actually pivot to laravel-app/public/index.php.
    // If someone refactors the path without updating docs/deploy/archivetool.md
    // we want to know.
    $body = (string) file_get_contents($shim);
    expect($body)
        ->toContain('/laravel-app')
        ->toContain('/public');
});

it('ships an executable post-deploy script with a bash shebang', function (): void {
    $script = base_path('deploy/post-deploy.sh');

    expect($script)->toBeFile();

    // Shebang check — bash, env-resolved so it works on every distro the
    // GH Actions runner may use.
    $firstLine = trim((string) (fgets(fopen($script, 'r')) ?: ''));
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

    // The deploy script MUST run with strict error handling — `set -e`
    // (or equivalent) protects against half-applied deploys.
    $body = (string) file_get_contents($script);
    expect($body)->toMatch('/set\s+-[A-Za-z]*e/');
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
        ->toContain('/home/archivet/laravel-app')
        ->toContain('/home/archivet/public_html')
        ->toContain('SSH_PRIVATE_KEY_DEPLOY')
        ->toContain('deploy/post-deploy.sh');
});

it('ships an .htaccess that rewrites cPanel root to the bootstrap shim', function (): void {
    $htaccess = base_path('deploy/cpanel-htaccess');

    expect($htaccess)->toBeFile();

    $body = (string) file_get_contents($htaccess);

    expect($body)
        ->toContain('RewriteEngine On')
        ->toContain('RewriteRule ^ index.php [L]')
        ->toMatch('/Require\s+all\s+denied/i');
});

it('wires the deploy workflow with required secrets and concurrency guard', function (): void {
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
        ->toContain("github.repository == 'fabiodalez-dev/batch-list-tool'");
});
