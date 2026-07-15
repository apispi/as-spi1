#!/usr/bin/env bash
#
# Deploy apispi.com to production on SiteGround.
#
# Two modes:
#
#   Local (default) — run from your machine:
#     ./deploy.sh [user@host] [--seed]
#     Builds assets, commits them, pushes, then SSHes in and runs this same
#     script in --server mode on SiteGround.
#
#   Server — runs ON SiteGround (invoked automatically over SSH, or manually):
#     ./deploy.sh --server [--seed]
#     Pulls code and runs the release steps: composer (only if the lockfile
#     changed), migrations, optional seeders, storage link, and cache warming,
#     all wrapped in maintenance mode with automatic recovery on failure.
#
# Flags:
#   --seed   Also run `php artisan db:seed --force` (seeders are idempotent;
#            off by default so routine deploys don't re-run them).
#
# Configuration (env vars, or pass as $1 in local mode):
#   SITEGROUND_SSH   SSH target, e.g. "user@123.45.67.89"
#   REMOTE_PATH      Remote app directory (default: ~/www/apispi.com)
#   PHP_BIN          PHP binary to use on the server (default: php)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# Collect flags that apply to either mode.
RUN_SEED=false
POSITIONAL=()
for arg in "$@"; do
  case "$arg" in
    --seed) RUN_SEED=true ;;
    *) POSITIONAL+=("$arg") ;;
  esac
done
set -- "${POSITIONAL[@]+"${POSITIONAL[@]}"}"

# ---------------------------------------------------------------------------
# Server mode: the release steps that run on SiteGround itself.
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--server" ]]; then
  PHP="${PHP_BIN:-php}"

  echo "==> [server] Pulling latest code"
  BEFORE_PULL="$(git rev-parse HEAD)"
  git pull --ff-only
  echo "    $(git rev-parse --short "$BEFORE_PULL") -> $(git rev-parse --short HEAD)"

  # Install PHP dependencies before any artisan call, in case new packages
  # arrived, but only when the lockfile actually changed.
  if ! git diff --quiet "$BEFORE_PULL" HEAD -- composer.lock 2>/dev/null; then
    echo "==> [server] composer.lock changed — installing dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    echo "==> [server] composer.lock unchanged — skipping composer install"
  fi

  # Enter maintenance mode, and guarantee we bring the app back up even if a
  # later step fails and the script exits early.
  echo "==> [server] Entering maintenance mode"
  "$PHP" artisan down --retry=15 || true
  trap '"$PHP" artisan up || true' EXIT

  echo "==> [server] Running migrations"
  "$PHP" artisan migrate --force

  if [[ "$RUN_SEED" == true ]]; then
    echo "==> [server] Running seeders"
    "$PHP" artisan db:seed --force
  fi

  # Ensure the public storage symlink exists (no-op if already linked).
  "$PHP" artisan storage:link 2>/dev/null || true

  # Warm the caches. Config and views are cached for performance; routes are
  # only cleared, never cached, because routes/web.php uses a closure route
  # which cannot be serialized by route:cache.
  echo "==> [server] Rebuilding caches"
  "$PHP" artisan config:clear
  "$PHP" artisan config:cache
  "$PHP" artisan view:cache
  "$PHP" artisan route:clear

  echo "==> [server] Leaving maintenance mode"
  "$PHP" artisan up
  trap - EXIT

  echo "==> [server] Deploy complete ($(git rev-parse --short HEAD))"
  exit 0
fi

# ---------------------------------------------------------------------------
# Local mode: build, commit assets, push, then trigger server mode over SSH.
# ---------------------------------------------------------------------------
SITEGROUND_SSH="${1:-${SITEGROUND_SSH:-}}"
REMOTE_PATH="${REMOTE_PATH:-~/www/apispi.com}"

if [[ -z "$SITEGROUND_SSH" ]]; then
  echo "Error: SiteGround SSH target not set." >&2
  echo "Usage: ./deploy.sh user@host [--seed]" >&2
  echo "   or: SITEGROUND_SSH=user@host ./deploy.sh [--seed]" >&2
  exit 1
fi

echo "==> Checking for uncommitted source changes"
UNSTAGED_NON_BUILD="$(git status --porcelain -- . ':!public_html/build' 2>/dev/null || true)"
if [[ -n "$UNSTAGED_NON_BUILD" ]]; then
  echo "Warning: you have uncommitted changes outside public_html/build:"
  echo "$UNSTAGED_NON_BUILD"
  echo "These will NOT be deployed unless you commit them first."
  read -r -p "Continue anyway? [y/N] " CONTINUE_ANYWAY
  if [[ ! "$CONTINUE_ANYWAY" =~ ^[Yy]$ ]]; then
    echo "Aborted. Commit your changes, then re-run."
    exit 1
  fi
fi

echo "==> Building frontend assets"
npm run build

echo "==> Staging built assets"
git add public_html/build/

if git diff --cached --quiet; then
  echo "==> No asset changes to commit"
else
  git status --short
  read -r -p "Commit message for asset rebuild [Rebuild frontend assets]: " COMMIT_MSG
  COMMIT_MSG="${COMMIT_MSG:-Rebuild frontend assets}"
  git commit -m "$COMMIT_MSG"
fi

echo "==> Pushing to origin"
git push

REMOTE_FLAGS="--server"
[[ "$RUN_SEED" == true ]] && REMOTE_FLAGS="$REMOTE_FLAGS --seed"

echo "==> Deploying on SiteGround (${SITEGROUND_SSH})"
ssh "$SITEGROUND_SSH" "cd ${REMOTE_PATH} && bash deploy.sh ${REMOTE_FLAGS}"

echo "==> Deploy complete"
