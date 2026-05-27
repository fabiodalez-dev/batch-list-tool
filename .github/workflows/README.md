# GitHub Actions workflows

CI/CD pipelines for `batch-list-tool`. All workflows live in this directory; they trigger automatically on push/PR or on the documented schedules.

| Workflow | File | Triggers | Purpose |
|---|---|---|---|
| Tests & Quality | `test.yml` | push + PR on every branch | Pest, PHPStan (Larastan), Pint, `composer audit` across PHP 8.3 + 8.4 |
| Static analysis | `phpstan.yml` | push + PR on `main` | Legacy standalone Larastan job (kept for parity with `main` branch protection rule referencing it) |
| Security | `security.yml` | push on `main`, weekly cron (Mon 06:00 UTC), manual | `composer audit` enforced, Semgrep SAST |
| Lint & build JS | `lint-js.yml` | push + PR touching JS/CSS/Vite/Tailwind config | `npm ci` + `npm run lint` (if defined) + `vite build` |
| Build & commit assets | `asset-build.yml` | push on `main` touching JS/CSS, manual | Build with Vite and commit `public/build/` back to `main` so the prod host (no Node) can deploy |

## Required repository secrets

Configure under **Settings → Secrets and variables → Actions**.

| Secret | Required by | Purpose | Default behaviour if missing |
|---|---|---|---|
| `GITHUB_TOKEN` | All write workflows | Built-in token, supplied automatically by GitHub | — |
| `SEMGREP_APP_TOKEN` | `security.yml` (Semgrep job) | Authenticates with Semgrep App for finding upload | Semgrep falls back to local `semgrep scan --config auto` (no upload) |

## Required repository variables

Configure under **Settings → Secrets and variables → Actions → Variables**.

| Variable | Required by | Purpose | Default if unset |
|---|---|---|---|
| `SEMGREP_ENABLED` | `security.yml` weekly cron | Set to `true` to run Semgrep on the scheduled run | Semgrep runs on push/PR only |

## Branch protection (recommended)

On `main`, require these status checks before merging:

- `Tests & Quality / PHP 8.3 · Pest + PHPStan + Pint`
- `Tests & Quality / PHP 8.4 · Pest + PHPStan + Pint`
- `Static analysis (Larastan) / phpstan`
- `Security / composer audit (security advisories)`

`asset-build.yml` is **not** a required check — it runs after merge and commits a follow-up.

## Notes & known caveats

- **Pest parallel mode disabled**: Filament boot + SQLite `:memory:` connections can race when run in parallel. Once the suite is stable, add `--parallel` to the Pest invocation in `test.yml`.
- **`public/build/` is gitignored**: `asset-build.yml` uses `add_options: --force` to commit it anyway. The production deploy on `archivetool.eu` has no Node and relies on this committed bundle.
- **Composer audit on PR vs main**: in `test.yml` it's advisory (won't fail the build). In `security.yml` (main + weekly) it's enforced.
- **Composer lockfile drift**: `composer install --no-scripts` is used so the Filament upgrade script doesn't try to write to a non-existent storage path during CI bootstrap. Post-install scripts are then invoked manually with `|| true` to be resilient.
