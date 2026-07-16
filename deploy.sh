#!/usr/bin/env bash
#
# Deploy apispi.com to production on SiteGround.
#
#   Local machine:  ./deploy.sh "commit message"
#     Builds Vite assets, commits, pushes, then updates the server over SSH
#     (which re-runs this script there in server mode).
#
#   On the server:  ./deploy.sh
#     Refreshes to origin/main, composer install --no-dev, migrate --force,
#     clears caches, verifies the site returns HTTP 200.
#
# Config (env vars):
#   DEPLOY_SSH_HOST      SSH host/alias        (default: as)
#   DEPLOY_SERVER_PATH   Remote app directory  (default: ~/www/apispi.com)
#   DEPLOY_SEED=1        Also run seeders on the server (idempotent; off by default)

set -euo pipefail

cd "$(dirname "$0")"

SSH_HOST="${DEPLOY_SSH_HOST:-as}"
SERVER_PATH="${DEPLOY_SERVER_PATH:-~/www/spi.apispi.com}"
SITE_URL="${DEPLOY_SITE_URL:-https://spi.apispi.com/}"

verify() {
    echo "==> Verifying live site..."
    CODE=$(curl -sS -o /dev/null -w "%{http_code}" "$SITE_URL")
    echo "    $SITE_URL -> HTTP $CODE"
    [ "$CODE" = "200" ] && echo "✓ Deploy complete." || { echo "✗ Site did not return 200."; exit 1; }
}

# ----------------------------------------------------------------- server mode
# Detected by running from inside the SiteGround web root (~/www/<site>).
# Matches any site directory so it is not tied to one domain name.
if [[ "$(pwd -P)" == */www/* ]]; then
    echo "==> Server mode: refreshing to origin/main..."
    git fetch -q origin
    git reset -q --hard origin/main

    echo "==> Installing composer dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction --quiet

    echo "==> Running migrations..."
    php artisan migrate --force

    if [[ "${DEPLOY_SEED:-}" == "1" ]]; then
        echo "==> Running seeders..."
        php artisan db:seed --force
    fi

    echo "==> Clearing caches..."
    php artisan optimize:clear >/dev/null

    verify
    exit 0
fi

# ------------------------------------------------------------------ local mode
MSG="${1:-Deploy site updates}"

echo "==> Building Vite assets..."
npm run build   # laravel-vite-plugin writes straight to public_html/build

echo "==> Committing..."
git add -A
if git diff --cached --quiet; then
    echo "    No changes to commit."
else
    git commit -m "$MSG"
fi

echo "==> Pushing to GitHub..."
git push origin "$(git rev-parse --abbrev-ref HEAD)"

echo "==> Updating server ($SSH_HOST)..."
ssh -o BatchMode=yes "$SSH_HOST" "cd $SERVER_PATH && git fetch -q origin && git reset -q --hard origin/main && ${DEPLOY_SEED:+DEPLOY_SEED=1 }./deploy.sh"
