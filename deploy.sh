#!/usr/bin/env bash
#
# Deploy apispi.com to production on SiteGround.
#
# Usage:
#   ./deploy.sh [user@host]
#
# Configuration (env vars, or edit the defaults below):
#   SITEGROUND_SSH   SSH target, e.g. "user@123.45.67.89" (required — or pass as $1)
#   REMOTE_PATH      Remote app directory (default: ~/www/apispi.com)
#
# What it does:
#   1. Builds frontend assets (npm run build)
#   2. Stages and commits ONLY the rebuilt public_html/build assets
#      (your application code changes should already be committed before running this)
#   3. Pushes to origin
#   4. SSHes in, pulls, and optionally runs migrations

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

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

echo "==> This deploy will run: git pull"
read -r -p "Does this deploy include new database migrations? [y/N] " RUN_MIGRATIONS
if [[ "$RUN_MIGRATIONS" =~ ^[Yy]$ ]]; then
  REMOTE_CMD="cd ${REMOTE_PATH} && git pull && php artisan migrate --force"
else
  REMOTE_CMD="cd ${REMOTE_PATH} && git pull"
fi

echo "==> Deploying on SiteGround (${SITEGROUND_SSH})"
echo "    Running: ${REMOTE_CMD}"
ssh "$SITEGROUND_SSH" "$REMOTE_CMD"

echo "==> Deploy complete"
