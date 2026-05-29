#!/usr/bin/env bash
#
# deploy/post-deploy.sh
# ---------------------
# Incremental deploy script run over SSH by the GitHub Actions workflow
# .github/workflows/deploy-archivetool.yml. Executed AS USER `archivet`
# on cpanel19.vhosting-it.com.
#
# Layout after the 2026-05-27 pivot:
#
#   /home/archivet/public_html/   <-- cPanel document root AND Laravel root
#       app/  bootstrap/  config/  database/  ...
#       .env                       <-- protected by deploy/cpanel-htaccess
#       public/index.php           <-- Laravel front controller
#       .htaccess                  <-- copy of deploy/cpanel-htaccess
#
# Assumes the first-time bootstrap (see docs/deploy/archivetool.md) has
# already been performed manually:
#   - repo cloned directly into /home/archivet/public_html
#   - .env populated with production secrets
#   - .htaccess copied from deploy/cpanel-htaccess
#   - storage/ writable, storage symlink created
#
# Idempotent: safe to re-run.

set -Eeuo pipefail

# ---- configuration ----------------------------------------------------------
# DEPLOY_PATH is the absolute path to the Laravel project on the server.
# After the pivot this is also the cPanel document root.
DEPLOY_PATH="${DEPLOY_PATH:-/home/archivet/public_html}"
# Back-compat: older invocations passed APP_DIR instead of DEPLOY_PATH.
APP_DIR="${APP_DIR:-${DEPLOY_PATH}}"
# Laravel 13 + Symfony 8.x require PHP >= 8.4. The cPanel default
# `/usr/local/bin/php` on cpanel19.vhosting-it.com still points at 8.3 —
# pin EA-PHP 8.4 explicitly. Fall back to the default if 8.4 isn't
# installed (which would mean we need to upgrade the server first).
PHP_BIN="${PHP_BIN:-/opt/cpanel/ea-php84/root/usr/bin/php}"
if [[ ! -x "${PHP_BIN}" ]]; then
    PHP_BIN="/usr/local/bin/php"
fi
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"
GIT_BIN="${GIT_BIN:-/usr/local/cpanel/3rdparty/lib/path-bin/git}"
BRANCH="${BRANCH:-main}"
RELEASE_TAG="$(date -u +%Y%m%dT%H%M%SZ)"
LOG_FILE="${APP_DIR}/storage/logs/deploy-${RELEASE_TAG}.log"

# ---- helpers ----------------------------------------------------------------
log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" | tee -a "${LOG_FILE}"
}

run() {
    log "+ $*"
    "$@" 2>&1 | tee -a "${LOG_FILE}"
}

abort() {
    log "DEPLOY FAILED at $(date -u +%Y-%m-%dT%H:%M:%SZ): $*"
    exit 1
}

trap 'abort "unexpected error on line ${LINENO}"' ERR

# ---- preflight --------------------------------------------------------------
[[ -d "${APP_DIR}" ]]            || abort "DEPLOY_PATH ${APP_DIR} does not exist (run first-time bootstrap)"
[[ -x "${PHP_BIN}" ]]            || abort "PHP binary not executable at ${PHP_BIN}"
[[ -x "${COMPOSER_BIN}" ]]       || abort "Composer binary not executable at ${COMPOSER_BIN}"
[[ -x "${GIT_BIN}" ]]            || abort "Git binary not executable at ${GIT_BIN}"
[[ -f "${APP_DIR}/.env" ]]       || abort ".env missing in ${APP_DIR} (populate manually first)"
[[ -d "${APP_DIR}/.git" ]]       || abort "${APP_DIR} is not a git working copy"

mkdir -p "$(dirname "${LOG_FILE}")"
: > "${LOG_FILE}"

log "==== Deploy ${RELEASE_TAG} starting on $(hostname) ===="
log "DEPLOY_PATH=${APP_DIR}"
log "BRANCH=${BRANCH}"
log "PHP=$( ${PHP_BIN} -r 'echo PHP_VERSION;')"
log "Composer=$( ${COMPOSER_BIN} --version 2>&1 | head -n1 )"

cd "${APP_DIR}"

# ---- enter maintenance mode -------------------------------------------------
# If artisan down fails (very first deploy or missing artisan), warn but
# continue - aborting here would leave the site broken.
run "${PHP_BIN}" artisan down --refresh=15 || \
    log "WARN: artisan down failed; continuing without maintenance flag"

# Ensure we restore the app even if a later step fails.
trap '${PHP_BIN} artisan up >/dev/null 2>&1 || true; abort "deploy failed, app restored to live"' ERR

# ---- pull latest source -----------------------------------------------------
run "${GIT_BIN}" remote -v
run "${GIT_BIN}" fetch --prune origin
run "${GIT_BIN}" reset --hard "origin/${BRANCH}"
# public/build is committed to git, so `reset --hard` restores the compiled
# assets and `clean -fd` leaves them untouched (it only removes untracked files).
run "${GIT_BIN}" clean -fd -e storage -e .env -e bootstrap/cache -e .htaccess

# ---- composer install -------------------------------------------------------
# Invoke composer via PHP_BIN so the platform-check satisfies the >= 8.4
# composer.json requirement even when the default shell `php` is 8.3.
run "${PHP_BIN}" "${COMPOSER_BIN}" install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress

# ---- database migration -----------------------------------------------------
# --force skips the production confirmation prompt; safe because secrets and
# the migration set are reviewed at PR time.
run "${PHP_BIN}" artisan migrate --force

# ---- cache pipeline ---------------------------------------------------------
# Always clear first so a stale config/route cache from the previous release
# can't leak into the new code.
run "${PHP_BIN}" artisan optimize:clear
run "${PHP_BIN}" artisan optimize

# Filament-specific caches (components + icons) - skip silently if the
# commands are absent (e.g. plugin removed in a future version).
${PHP_BIN} artisan filament:cache-components 2>&1 | tee -a "${LOG_FILE}" || \
    log "INFO: filament:cache-components not available"
${PHP_BIN} artisan icons:cache 2>&1 | tee -a "${LOG_FILE}" || \
    log "INFO: icons:cache not available"

# ---- storage symlink (idempotent) -------------------------------------------
# Recreates public/storage -> ../storage/app/public so /storage/* URLs serve
# from storage/app/public/ via the (a)-block rewrite in .htaccess.
run "${PHP_BIN}" artisan storage:link || \
    log "WARN: storage:link reported an error (existing link?); continuing"

# ---- permission hardening ---------------------------------------------------
# storage/ and bootstrap/cache/ must be writable by the web user; everything
# else stays at the default 644/755 left by git.
chmod -R 775 "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" 2>/dev/null || \
    log "WARN: chmod 775 on storage/bootstrap/cache failed (already correct?)"
chmod 600 "${APP_DIR}/.env" 2>/dev/null || \
    log "WARN: chmod 600 on .env failed"

# ---- reinstall the cPanel .htaccess ----------------------------------------
# The repo's deploy/cpanel-htaccess is the source of truth for the .htaccess
# at the document root. Re-copy it on every deploy so any drift between
# deploy/cpanel-htaccess and public_html/.htaccess is corrected automatically.
if [[ -f "${APP_DIR}/deploy/cpanel-htaccess" ]]; then
    cp -f "${APP_DIR}/deploy/cpanel-htaccess" "${APP_DIR}/.htaccess"
    chmod 644 "${APP_DIR}/.htaccess"
    log "Refreshed ${APP_DIR}/.htaccess from deploy/cpanel-htaccess"
else
    log "WARN: deploy/cpanel-htaccess missing; .htaccess not refreshed"
fi

# ---- leave maintenance mode -------------------------------------------------
trap - ERR
run "${PHP_BIN}" artisan up

log "==== Deploy ${RELEASE_TAG} completed successfully at $(date -u +%FT%TZ) ===="
