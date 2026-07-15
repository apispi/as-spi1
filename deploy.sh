#!/usr/bin/env bash
#
# Deploy apispi.com to production on SiteGround.
#
# Two modes:
#
#   Local (default) — run from your machine:
#     ./deploy.sh [user@host]
#     Builds assets, commits them, pushes, then SSHes in and runs this same
#     script in --server mode on SiteGround.
#
#   Server — runs ON SiteGround (invoked automatically over SSH, or manually):
#     ./deploy.sh --server
#     Pulls, installs composer deps if the lockfile changed, runs migrations,
#     and clears Laravel caches.
#
# Configuration (env vars, or pass as $1 in local mode):
#   SITEGROUND_SSH   SSH target, e.g. "user@123.45.67.89"
#   REMOTE_PATH      Remote app directory (default: ~/www/apispi.com)

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# ---------------------------------------------------------------------------
# Server mode: the steps that run on SiteGround itself.
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--server" ]]; then
  echo "==> [server] Pulling latest code"
  BEFORE_PULL="$(git rev-parse HEAD)"
  git pull --ff-only

  if ! git diff --quiet "$BEFORE_PULL" HEAD -- composer.lock 2>/dev/null; then
    echo "==> [server] composer.lock changed — installing dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction
  else
    echo "==> [server] composer.lock unchanged — skipping composer install"
  fi

  echo "==> [server] Running migrations"
  php artisan migrate --force

  echo "==> [server] Clearing caches"
  php artisan config:clear
  php artisan route:clear
  php artisan view:clear

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
  echo "Usage: ./deploy.sh user@host" >&2
  echo "   or: SITEGROUND_SSH=user@host ./deploy.sh" >&2
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

echo "==> Deploying on SiteGround (${SITEGROUND_SSH})"
ssh "$SITEGROUND_SSH" "cd ${REMOTE_PATH} && bash deploy.sh --server"

echo "==> Deploy complete"
