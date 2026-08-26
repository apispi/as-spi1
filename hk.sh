#!/usr/bin/env bash
#================================================================
# LOCAL Housekeeping Assistant - hk.sh
# - this script runs on your LOCAL machine, from the repo root
# - DO NOT COPY TO REMOTE (deploy.sh handles the server side)
#
# Usage:
#   ./hk.sh          menu, then pick an option
#   ./hk.sh 03       run one option directly
#   ./hk.sh 91 "msg" pass a parameter to the option
#
# Version 1.0
#================================================================
clear

cd "$(dirname "$0")" || exit 1
PROJECT_ROOT="$(pwd -P)"
PROJECT="$(basename "$PROJECT_ROOT")"
LOG_DIR="./log"
LOG_FILE="$LOG_DIR/housekeeping.log"

[ -d "$LOG_DIR" ] || mkdir -p "$LOG_DIR"

# Record what was run, newest first, the way cx.sh keeps its history.
note () {
  printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" > tmpfile
  [ -f "$LOG_FILE" ] && cat "$LOG_FILE" >> tmpfile
  mv tmpfile "$LOG_FILE"
}

# Confirm before anything destructive. Returns non-zero if the user declines.
confirm () {
  printf '%s [y/N] ' "$1"
  read -r reply
  [ "$reply" = "y" ] || [ "$reply" = "Y" ]
}

rule ()   { echo -------------------------------------------------------------; }
banner () { echo ===========================================================; echo "$1"; echo ===========================================================; }

echo =============================================================
echo "Hi $USER@$HOSTNAME. You are in $PROJECT ($PROJECT_ROOT)."
echo "Branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'not a git repo')"
echo What do you want to do?
rule
echo CHECK
echo "00 : STATUS: branch, pending migrations, outdated packages"
echo "01 : TEST: run the full test suite"
echo "02 : BUILD: compile front-end assets"
echo "03 : AUDIT: composer security advisories"
echo "04 : LINT: format PHP with Pint"
echo "05 : PREFLIGHT: build + test + audit (run before pushing)"
rule
echo DATABASE
echo "10 : MIGRATE: apply pending migrations"
echo "11 : SEED: run seeders (idempotent)"
echo "12 : FRESH: drop, migrate and seed  [DESTRUCTIVE]"
echo "13 : ADMIN: reset the local admin password"
rule
echo RUN
echo "20 : SERVE: composer dev (server, queue, logs, vite)"
echo "21 : MONITORS: run every monitor that is due"
echo "22 : CACHE: clear config, route, view and event caches"
rule
echo SHIP
echo "30 : PUSH: preflight, then push the current branch"
echo "31 : DEPLOY: run deploy.sh (builds, commits, pushes, updates server)"
rule
echo HOUSEKEEPING
echo "90 : CLEAN: clear local logs and compiled views"
echo "91 : HISTORY: show this script's log"
echo "99 : DOCTOR: check tooling, .env and service reachability"
echo "qq : Exit [Quit]"
echo Enter [Selection] to continue
echo =============================================================

if [ -n "$1" ]; then
  SELECTION=$1
else
  read -r -n 2 SELECTION
  echo
fi
PARAM2=${2:-}

echo "Your selection is : $SELECTION."
[ -n "$PARAM2" ] && echo "Your parameter is : $PARAM2."


case "$SELECTION" in

  "00" )
  banner "STATUS"
  echo "Git:"
  git status --short --branch | head -20
  rule
  echo "Pending migrations:"
  php artisan migrate:status 2>/dev/null | grep -i "pending" || echo "  none"
  rule
  echo "Outdated direct dependencies:"
  composer outdated --direct 2>/dev/null || echo "  (composer unavailable)"
  rule
  php artisan about --only=environment 2>/dev/null | grep -viE "deprecated|warning"
  note "Status check"
  ;;

  "01" )
  banner "TEST: full suite"
  php artisan test
  note "Ran the test suite"
  ;;

  "02" )
  banner "BUILD: front-end assets"
  npm run build
  note "Built assets"
  ;;

  "03" )
  banner "AUDIT: security advisories"
  composer audit
  note "Security audit"
  ;;

  "04" )
  banner "LINT: Pint"
  ./vendor/bin/pint
  note "Formatted with Pint"
  ;;

  "05" )
  banner "PREFLIGHT: build + test + audit"
  # The same gate CLAUDE.md asks for before pushing: assets built and tests
  # green, so a broken build never reaches the server.
  npm run build && php artisan test && composer audit
  STATUS=$?
  rule
  if [ $STATUS -eq 0 ]; then
    echo "PREFLIGHT PASSED - safe to push."
  else
    echo "PREFLIGHT FAILED - do not push."
  fi
  note "Preflight (exit $STATUS)"
  exit $STATUS
  ;;

  "10" )
  banner "MIGRATE"
  php artisan migrate
  note "Migrated"
  ;;

  "11" )
  banner "SEED"
  php artisan db:seed
  note "Seeded"
  ;;

  "12" )
  banner "FRESH DATABASE [DESTRUCTIVE]"
  echo "This DROPS every table in the local database and re-seeds it."
  php artisan about --only=drivers 2>/dev/null | grep -i database
  if confirm "Really wipe the local database?"; then
    php artisan migrate:fresh --seed
    note "Rebuilt the database from scratch"
  else
    echo "Cancelled."
  fi
  ;;

  "13" )
  banner "RESET LOCAL ADMIN PASSWORD"
  # Passwords are stored hashed, so an existing one cannot be recovered —
  # only replaced. ADMIN_PASSWORD in .env is what the seeder uses.
  printf 'New password for admin@apispi.com: '
  read -r NEWPASS
  if [ -z "$NEWPASS" ]; then
    echo "No password entered. Cancelled."
  else
    php artisan tinker --execute="\App\Models\User::updateOrCreate(['email'=>'admin@apispi.com'],['name'=>'Admin','password'=>bcrypt('$NEWPASS'),'is_admin'=>true]);" \
      && echo "Updated. Sign in as admin@apispi.com"
    note "Reset the local admin password"
  fi
  ;;

  "20" )
  banner "SERVE: composer dev"
  echo "Runs server, queue, logs and vite together. Ctrl-C to stop."
  composer dev
  ;;

  "21" )
  banner "MONITORS: run those that are due"
  # On the server this happens via cron -> schedule:run; locally it is manual.
  php artisan monitors:run
  note "Ran due monitors"
  ;;

  "22" )
  banner "CACHE: clear"
  php artisan optimize:clear
  note "Cleared caches"
  ;;

  "30" )
  banner "PUSH: preflight, then push"
  BRANCH="$(git rev-parse --abbrev-ref HEAD)"
  if npm run build && php artisan test; then
    rule
    git status --short
    if confirm "Push $BRANCH to origin?"; then
      git push origin "$BRANCH"
      note "Pushed $BRANCH"
    else
      echo "Cancelled."
    fi
  else
    echo "Build or tests failed - not pushing."
    exit 1
  fi
  ;;

  "31" )
  banner "DEPLOY: deploy.sh"
  # deploy.sh does its own build/commit/push, then updates the server. See the
  # header of that script for the env vars it accepts.
  if [ -x ./deploy.sh ]; then
    ./deploy.sh "${PARAM2:-Deploy site updates}"
    note "Deployed: ${PARAM2:-Deploy site updates}"
  else
    echo "deploy.sh is missing or not executable."
  fi
  ;;

  "90" )
  banner "CLEAN: local logs and compiled views"
  echo "Before:"
  du -sh storage/logs storage/framework/views 2>/dev/null
  if confirm "Clear local logs and compiled views?"; then
    find storage/logs -name '*.log' -type f -delete 2>/dev/null
    php artisan view:clear
    echo "After:"
    du -sh storage/logs storage/framework/views 2>/dev/null
    note "Cleaned logs and compiled views"
  else
    echo "Cancelled."
  fi
  ;;

  "91" )
  banner "HISTORY"
  if [ -f "$LOG_FILE" ]; then
    head -30 "$LOG_FILE"
  else
    echo "No history yet."
  fi
  ;;

  "99" )
  banner "DOCTOR"
  for tool in php composer npm node git; do
    if command -v "$tool" >/dev/null 2>&1; then
      printf '  %-9s %s\n' "$tool" "$($tool --version 2>&1 | head -1)"
    else
      printf '  %-9s MISSING\n' "$tool"
    fi
  done
  rule
  echo ".env checks:"
  for key in APP_ENV APP_DEBUG DB_CONNECTION DB_DATABASE MAIL_MAILER; do
    printf '  %-16s %s\n' "$key" "$(grep -E "^$key=" .env 2>/dev/null | cut -d= -f2- || echo '(unset)')"
  done
  # Alerting and scheduling are the two things that silently do nothing when
  # unconfigured, so call them out rather than leaving them to be discovered.
  grep -qE '^MAIL_MAILER=(log|array)' .env 2>/dev/null \
    && echo "  NOTE: mail goes to the log - monitor email alerts will not send."
  grep -qE '^ADMIN_PASSWORD=.+' .env 2>/dev/null \
    || echo "  NOTE: ADMIN_PASSWORD unset - the seeder generates a random one, shown once."
  rule
  echo "Database:"
  # Deliberately not `db:show`: on a shared MySQL server that prints every
  # schema on the host, including other projects. Only this connection matters.
  php artisan tinker --execute="
    \$c = config('database.default');
    \$n = \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
    \$t = count(\Illuminate\Support\Facades\DB::select('show tables'));
    echo '  connection      '.\$c.PHP_EOL.'  database        '.\$n.PHP_EOL.'  tables          '.\$t.PHP_EOL;
  " 2>/dev/null | grep -E "^  (connection|database|tables)" || echo "  could not connect"
  ;;

  "qq" )
  echo Quit
  exit 0
  ;;

  * )
  echo
  echo "Not a recognized option."
  ;;

esac
