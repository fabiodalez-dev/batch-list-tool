# Batch List Tool

[![tests](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/test.yml/badge.svg)](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/test.yml)
[![static analysis](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/phpstan.yml/badge.svg)](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/phpstan.yml)
[![security](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/security.yml/badge.svg)](https://github.com/fabiodalez-dev/batch-list-tool/actions/workflows/security.yml)

Web application for the **Notarial Archives Foundation (NAF)**, Malta, under contract **RFQ-2026-06**. Replaces the legacy Excel-based "Batch List" with a structured, auditable, multi-repository system.

- **Production go-live**: 30 November 2026
- **Stack**: Laravel 11 · Filament 3 · PHP 8.4 · MySQL 8 · Blade/Livewire (no SPA)
- **Licence**: Apache 2.0 (to be added at handover)
- **Operational docs**: `../Batch_List_Tool/nra/ops/` (planning, workflow, runbooks)

---

## Quick start (local development)

```bash
git clone git@github.com:fabiodalez-dev/batch-list-tool.git
cd batch-list-tool
cp .env.example .env                    # then edit DB_* values for your local MySQL
composer install
npm install && npm run build            # or `npm run dev` while developing
php artisan key:generate
php artisan migrate
php artisan db:seed                     # seeds 2 repos, 5 series, 4 authorities, 4 batches, 3 boxes
php artisan serve
```

Open <http://127.0.0.1:8000/admin>.

### Default admin (local dev only)

| Field | Value |
|---|---|
| Email | `admin@batchlist.local` |
| Password | `ChangeMe!Local2026` |
| Role | `super_admin` |

**⚠ Local-only credentials.** Staging and production use rotated passwords delivered via Bitwarden Send.

---

## Architecture overview

### Domain entities (9 + auxiliaries)

| Table | Purpose | RFQ ref |
|---|---|---|
| `repositories` | Multi-tenant unit; every user is scoped to at least one | §3.5.1 |
| `series` | Document series (R, REG, RWL, OWL, O) | §1 |
| `authorities` | Notaries / Creators | §1 |
| `batches` | Logical group of boxes (1-99) | §2 |
| `accessions` | Formal acquisition events | §1 |
| `boxes` | Physical containers (RAS, IN_SITU, NRA, MAV, STVC) | §2 |
| `documents` | Central archival entity | §1, §3 |
| `document_authority` | M:N pivot Document ↔ Authority | §1 |
| `volumes` | First-class child of Document | §1 |
| `box_movements` | Lifecycle tracking, replaces legacy `ras_batch_*` columns | §3.1 |
| `audits` | `owen-it/laravel-auditing` — every CRUD captured | §3.1.5 |
| `media` | `spatie/laravel-medialibrary` — document attachments | §1 |

### RFQ Appendix-1 validation rules

| # | Rule | Enforcement |
|---|---|---|
| 1 | Batch numbers 33, 34, 36 forbidden | MySQL CHECK on `batches.batch_number` + app-level guard |
| 2 | Wills documents must live in batch 50 | `Series.is_wills_series` + observer/state machine on Document |
| 3 | IN_SITU / NRA boxes require parent RAS | `Box.requiresParent()` + FormRequest |
| 4 | MAV / STVC types legacy-only | MySQL CHECK requiring `is_legacy = 1` |
| 5 | `barcode_status = PERM_OUT` requires `disinfestation_date` | MySQL CHECK |

### Composer packages (production)

Auth & RBAC: `laravel/fortify`, `spatie/laravel-permission`, `bezhansalleh/filament-shield`.
Audit: `owen-it/laravel-auditing` (all 9 domain models).
Admin UI: `filament/filament` 3.x.
Domain: `spatie/laravel-model-states`, `spatie/laravel-schemaless-attributes`, `spatie/laravel-tags`, `spatie/eloquent-sortable`, `staudenmeir/eloquent-has-many-deep`, `kirschbaum-development/eloquent-power-joins`.
I/O: `maatwebsite/excel`, `barryvdh/laravel-dompdf`, `laravel/scout` (database driver).
Media: `spatie/laravel-medialibrary`.
Backup & Ops: `spatie/laravel-backup`, `spatie/laravel-health`, `laravel/pulse` (migration skipped on MySQL 9, see Known issues), `opcodesio/log-viewer`.
Security: `bepsvpt/secure-headers`, `spatie/laravel-csp`, `spatie/laravel-honeypot`.

### Dev packages

`larastan/larastan`, `laravel/telescope`, `barryvdh/laravel-debugbar`.

(Pest is **not** installed yet — Pest 4 requires PHPUnit 12 which conflicts with the Laravel 11 default. To revisit when Laravel 12 lands.)

---

## Branching strategy

| Branch | Purpose |
|---|---|
| `main` | Always-deployable production code |
| `staging` | Auto-deployed to `staging.archivetool.eu` |
| `feat/<name>`, `fix/<name>`, `chore/<name>` | Short-lived feature/fix branches |

PR workflow + branch protection rules + CI/CD documented in `../Batch_List_Tool/nra/ops/workflow.md`.

---

## Known issues

- **Pulse migration skipped on MySQL 9**: `pulse_values` and friends use `unhex(md5(...))` as a generated column, which MySQL 9 disallows. Migration file moved to `_skipped_migrations/`. To address: patch the Pulse migration to use a SHA2-based column (32 chars) or wait for Pulse upstream MySQL 9 support.
- **`config:cache` not yet run**: avoid `php artisan config:cache` until staging — some service providers (Telescope) register only in non-cached state during early dev.

---

## Roadmap

See `../Batch_List_Tool/nra/ops/plan.md` for the master plan (W0 → W26), milestone acceptance criteria, and the consolidated package catalog.

Current state: **W0 complete**. Next: **W1 — Eloquent foundations + audit trait verification, requirements analysis kick-off with NAF**.
