#!/usr/bin/env bash
#
# deploy/post-deploy.sh
# ---------------------
# Incremental deploy script run over SSH by the GitHub Actions workflow
# .github/workflows/deploy-archivetool.yml. Executed AS USER `archivet`
# on cpanel19.vhosting-it.com.
#
# Assumes the first-time bootstrap (see docs/deploy/archivetool.md) has
# already been performed manually:
#   - repo cloned to /home/archivet/laravel-app
#   - .env populated with production secrets
#   - public_html/index.php and public_html/.htaccess in place
#   - storage/ writable, storage symlink created
#
# Idempotent: safe to re-run.

set -Eeuo pipefail

# ---- configuration ----------------------------------------------------------
APP_DIR="${APP_DIR:-/home/archivet/laravel-app}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
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
[[ -d "${APP_DIR}" ]]            || abort "APP_DIR ${APP_DIR} does not exist (run first-time bootstrap)"
[[ -x "${PHP_BIN}" ]]            || abort "PHP binary not executable at ${PHP_BIN}"
[[ -x "${COMPOSER_BIN}" ]]       || abort "Composer binary not executable at ${COMPOSER_BIN}"
[[ -x "${GIT_BIN}" ]]            || abort "Git binary not executable at ${GIT_BIN}"
[[ -f "${APP_DIR}/.env" ]]       || abort ".env missing in ${APP_DIR} (populate manually first)"
[[ -d "${APP_DIR}/.git" ]]       || abort "${APP_DIR} is not a git working copy"

mkdir -p "$(dirname "${LOG_FILE}")"
: > "${LOG_FILE}"

log "==== Deploy ${RELEASE_TAG} starting on $(hostname) ===="
log "APP_DIR=${APP_DIR}"
log "BRANCH=${BRANCH}"
log "PHP=$( ${PHP_BIN} -r 'echo PHP_VERSION;')"
log "Composer=$( ${COMPOSER_BIN} --version 2>&1 | head -n1 )"

cd "${APP_DIR}"

# ---- enter maintenance mode -------------------------------------------------
# Best-effort: storage/framework/maintenance.php must be reachable through the
# shim. If the down command fails (very first deploy or missing artisan), we
# warn but continue, because aborting here would leave the site broken.
run "${PHP_BIN}" artisan down --render="errors::503" --retry=15 || \
    log "WARN: artisan down failed; continuing without maintenance flag"

# Ensure we restore the app even if a later step fails.
trap '${PHP_BIN} artisan up >/dev/null 2>&1 || true; abort "deploy failed, app restored to live"' ERR

# ---- pull latest source -----------------------------------------------------
run "${GIT_BIN}" remote -v
run "${GIT_BIN}" fetch --prune origin
run "${GIT_BIN}" reset --hard "origin/${BRANCH}"
run "${GIT_BIN}" clean -fd -e storage -e .env -e bootstrap/cache

# ---- composer install -------------------------------------------------------
run "${COMPOSER_BIN}" install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    --no-progress

# ---- database migration -----------------------------------------------------
# --force skips the production confirmation prompt; safe because secrets and
# the migration set are reviewed at PR time.
run "${PHP_BIN}" artisan migrate --force

# ---- storage symlink (idempotent) -------------------------------------------
# storage:link is a no-op if the symlink already exists and points where it
# should; we still run it so a wiped public_html gets the link recreated.
run "${PHP_BIN}" artisan storage:link || \
    log "WARN: storage:link reported an error (existing link?); continuing"

# ---- cache pipeline ---------------------------------------------------------
# Always clear first so a stale config/route cache from the previous release
# can't leak into the new code.
run "${PHP_BIN}" artisan optimize:clear
run "${PHP_BIN}" artisan optimize

# Filament 5 specific caches (icons + components) — skip silently if the
# commands are absent (e.g. plugin removed in a future version).
${PHP_BIN} artisan filament:cache-components 2>&1 | tee -a "${LOG_FILE}" || \
    log "INFO: filament:cache-components not available"
${PHP_BIN} artisan icons:cache 2>&1 | tee -a "${LOG_FILE}" || \
    log "INFO: icons:cache not available"

# ---- permission hardening ---------------------------------------------------
# storage/ and bootstrap/cache/ must be writable by the web user; everything
# else stays at the default 644/755 left by git.
run find "${APP_DIR}/storage" -type d -exec chmod 775 {} +
run find "${APP_DIR}/storage" -type f -exec chmod 664 {} +
run find "${APP_DIR}/bootstrap/cache" -type d -exec chmod 775 {} +
run find "${APP_DIR}/bootstrap/cache" -type f -exec chmod 664 {} +
run chmod 600 "${APP_DIR}/.env"

# ---- leave maintenance mode -------------------------------------------------
trap - ERR
run "${PHP_BIN}" artisan up

log "==== Deploy ${RELEASE_TAG} completed successfully ===="
