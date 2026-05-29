# Settings & User Management — Design Spec

**Date:** 2026-05-29
**Status:** Approved (brainstorming) — ready for implementation plan
**Stack:** Laravel 13.11 · Filament 5.6 · filament-shield 4.2 · Fortify 1.37 · spatie/laravel-permission 7.4 · owen-it/laravel-auditing · MySQL · PHP 8.4

## 1. Goal

Give the application a real **Settings** area where:
- **Administrators** create and manage users (the central gap — no `UserResource` exists today), assign roles and repositories, and configure app-level settings (branding, backup, audit).
- **Every user** manages their own account (password, 2FA, default repository).
- Every privileged action is **auditable**, and an admin can review **what a given user has done** (per-user activity log).

Constraints (project rules): **no CDN** (logo/fonts/assets served locally), enforce **multi-repository scoping**, follow existing Filament-native patterns, keep everything **auditable**.

## 2. Information Architecture (chosen: Filament-native nav groups)

Two new navigation groups + cleanup of existing ones:

- **Administration** (gated to `super_admin` / `admin`):
  - `Users` — **new** `UserResource`
  - `Repositories` — existing `RepositoryResource` (add Users relation manager)
  - `Roles & permissions` — link to existing Shield `RoleResource`, **visible only to `super_admin`**
  - `Field permissions` — existing `FieldPermissionMatrix`
  - `Branding` — **new** settings page
  - `Backup & health` — **new** page
  - `Audit` — **new** settings page (`super_admin` only) + the existing read-only `AuditResource`
- **My account** (any authenticated user):
  - `Profile & password` — existing Fortify `->profile()`
  - `Two-factor` — existing `TwoFactorProfile`
  - `Preferences` — **new** (default repository self-service)
- **Cleanup:** merge the duplicate `Reference` / `Reference data` nav groups into one.

## 3. User Management — `UserResource` (core)

### 3.1 Data model
- New migration: `users.must_change_password` (boolean, default `false`).
- Reuse existing columns: `name`, `email`, `password`, `is_active`, `default_repository_id`, 2FA columns, soft-deletes, `repository_user` pivot (`is_default`).

### 3.2 Create/Edit form (per approved mockup)
- **Identity:** name, email (unique).
- **Initial access:** temporary password + confirm, "Generate secure password" button (copy to clipboard), `must_change_password` checkbox **locked ON** at create.
- **Role & access:** single role select (Administrator / ReadingRoom / General via `RoleLabels`); repositories multi-select; default repository select (must be one of the assigned); `is_active` toggle.
- **Edit mode adds:** "Reset password" action (regenerates a temp password + re-arms `must_change_password`); activate/deactivate; soft-delete/restore; 2FA status (+ optional "require 2FA").

### 3.3 First-login forced password change
- Middleware on the panel: if `auth()->user()->must_change_password`, redirect to the change-password screen and block all other panel routes until changed. On successful change, clear the flag.

### 3.4 Authorization (`UserPolicy` + Shield `*_user` permissions)
- `viewAny/view/create/update/delete` gated to `super_admin` / `admin`.
- **Anti-escalation guards (enforced in policy + form + model):**
  - An `admin` cannot see, assign, or grant the `super_admin` role (only `super_admin` can).
  - A user cannot deactivate, delete, or demote **themselves** (no lock-out).
  - An `admin` cannot edit/delete a `super_admin` account.
- Generate the new `*_user` permissions via `shield:generate`.

### 3.5 Repositories ↔ users
- Add a `UsersRelationManager` to `RepositoryResource` (currently `getRelations()` is empty) so the pivot is manageable from both sides.

## 4. Settings storage — `spatie/laravel-settings`

- Install `spatie/laravel-settings`; one settings class per group: `BrandingSettings`, `BackupSettings`, `AuditSettings`. Values seeded from current defaults via settings migrations.
- Settings are typed, cached, and migration-versioned. Changing a setting is audited (wrap writes so they emit an audit entry; the settings pages live under admin gating).

## 5. Branding settings page
- Fields: `brand_name`, `logo` (upload via existing `spatie/medialibrary`, served locally — **never a CDN URL**), `logo_height`, `primary_color`.
- `AdminPanelProvider` reads `BrandingSettings` with fallback to current hardcoded defaults (`Batch List Tool`, `/images/brand-logo.png`, `2.25rem`, dusty-sandstone palette).

## 6. Backup & health page
- Read-only widgets: last backup status/age (from `spatie/laravel-backup` destinations), disk usage, DB/schedule/backup-freshness (reuse the checks behind `/health`).
- Action **"Run backup now"** → dispatches `backup:run` on the queue, gated to `super_admin`/`admin`, audited.
- Editable **retention** (keep-daily/weekly/monthly) persisted via `BackupSettings` and read by the backup config.

## 7. Audit settings page (`super_admin` only)
- Toggle `auditing enabled` (`AuditSettings.enabled`); per-model record `threshold`.
- These map onto `config/audit.php` values read at runtime from `AuditSettings`.

## 8. Per-user activity logging

The app already logs model CRUD via `owen-it/laravel-auditing` (`audits` table: user_id, event, old/new, ip, ua). This design **surfaces** it and **fills the auth gap**:
- **Surface:** an `Activity` tab/relation on `UserResource` (and an "View activity" header action) showing that user's `audits` rows, reusing `AuditResource` filters; deep-link `AuditResource` pre-filtered by `user_id`.
- **Auth events:** event listeners for `Login`, `Logout`, `Failed`, `Lockout`, `PasswordReset`, and 2FA challenge outcomes, recorded into the audit/activity stream (extend the existing impersonation audit listener pattern). Capture ip + user-agent.
- No new heavy dependency: stay on `owen-it/laravel-auditing`; auth events written as audit entries (or a thin `user_activity` view over `audits`).

## 9. Self-service — My account › Preferences
- A page where the current user changes their own `default_repository_id`, limited to repositories they belong to. Audited.

## 10. Testing (Pest)
- `UserResource`: create sets `must_change_password`; generated password works; default repo must be within assigned repos.
- **Escalation guards:** admin cannot assign `super_admin`; cannot self-deactivate/demote; cannot edit a super_admin.
- `UserPolicy` matrix per role.
- First-login middleware: forced redirect until password changed, then cleared.
- Branding: saved values applied by the panel provider; logo stays local (no external URL accepted).
- Backup: "Run backup now" gated + dispatches; retention persisted.
- Audit toggle: disabling stops new audit rows.
- Activity: a user's actions appear in their activity tab; login/failed-login events recorded.
- Multi-repository scoping preserved across all new screens.

## 11. Out of scope (v1)
- Per-repository roles (the `repository_user` pivot stays role-less; roles remain global). Re-word the submission's "different roles per repository" claim separately.
- Email-based invitations (SMTP not assumed) — password is admin-set + forced change.
- Avatar upload (keep generated SVG) unless requested later.
- Exposing box/barcode/batch-type enums as editable lookups (kept as enums + DB CHECK for integrity).

## 12. Navigation/permission summary
| Area | Who | Source |
|---|---|---|
| Users (CRUD) | super_admin + admin (no super_admin escalation) | new UserResource + UserPolicy |
| Roles & permissions editor | super_admin only | existing Shield |
| Field permissions | super_admin + admin | existing page |
| Branding | super_admin + admin | new page + BrandingSettings |
| Backup & health | super_admin + admin (run: same) | new page + BackupSettings |
| Audit settings | super_admin only | new page + AuditSettings |
| My account (profile/2FA/preferences) | any authenticated user | Fortify + existing + new Preferences |
| Activity log (per user) | super_admin + admin | audits + auth listeners |
