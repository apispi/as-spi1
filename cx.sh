#!/usr/bin/env bash
#================================================================
# Console / Admin Assistant - cx.sh
# - routine ADMIN operations for the Spi application
# - the operational companion to hk.sh (which handles dev housekeeping)
# - acts on the CURRENT environment's database (local by default); on the
#   server it acts on production, so read the banners before confirming.
#
# Usage:
#   ./cx.sh              menu, then pick an option
#   ./cx.sh 33           run one option directly
#   ./cx.sh 02 a@b.com   pass a parameter (e.g. an email) to the option
#
# Version 1.0
#================================================================
clear

cd "$(dirname "$0")" || exit 1
PROJECT_ROOT="$(pwd -P)"
PROJECT="$(basename "$PROJECT_ROOT")"
LOG_DIR="./log"
LOG_FILE="$LOG_DIR/console.log"

[ -d "$LOG_DIR" ] || mkdir -p "$LOG_DIR"

# Record what was run, newest first.
note () {
  printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" > tmpfile
  [ -f "$LOG_FILE" ] && cat "$LOG_FILE" >> tmpfile
  mv tmpfile "$LOG_FILE"
}

# Confirm before anything destructive. Returns non-zero if the user declines.
# Set CX_YES=1 to auto-approve (for scripting routine tasks unattended).
confirm () {
  if [ "${CX_YES:-}" = "1" ]; then echo "$1 [auto-yes]"; return 0; fi
  printf '%s [y/N] ' "$1"
  read -r reply
  [ "$reply" = "y" ] || [ "$reply" = "Y" ]
}

rule ()   { echo -------------------------------------------------------------; }
banner () { echo ===========================================================; echo "$1"; echo ===========================================================; }

# Run a PHP snippet through tinker and print only its output.
tink () { php artisan tinker --execute="$1" 2>/dev/null; }

# Ask for an email if one was not passed as the second argument.
need_email () {
  EMAIL="$PARAM2"
  if [ -z "$EMAIL" ]; then
    printf 'User email: '
    read -r EMAIL
  fi
  [ -n "$EMAIL" ]
}

# Which database are we about to touch? Printed before anything destructive so
# an accidental run on production is obvious.
db_context () {
  tink "echo 'DB: '.config('database.default').' / '.\Illuminate\Support\Facades\DB::connection()->getDatabaseName().' ('.config('app.env').')';" \
    | grep -E "^DB:" || echo "DB: (could not determine)"
}

echo =============================================================
echo "Hi $USER@$HOSTNAME. Admin console for $PROJECT."
echo "$(db_context)"
echo What do you want to do?
rule
echo USERS
echo "01 : ADMINS: list admin accounts"
echo "02 : PROMOTE: make a user an admin        (email)"
echo "03 : DEMOTE: remove admin from a user      (email)"
echo "04 : PASSWORD: reset a user's password     (email)"
echo "05 : NO-2FA: admins without two-factor on"
echo "06 : RECENT: newest sign-ups"
echo "07 : LOOKUP: full detail for a user        (email)"
echo "08 : DEACTIVATE: soft-delete a user        (email)  [reversible]"
echo "09 : RESTORE: restore a soft-deleted user  (email)"
rule
echo ORGANISATIONS
echo "40 : ORGS: list organisations with member counts"
echo "41 : ASSIGN: move a user into an org       (email org-slug)"
echo "42 : UNASSIGN: remove a user from its org  (email)"
rule
echo SECURITY
echo "10 : FAILED: failed sign-ins, last 24h, by IP"
echo "11 : SIGNOUT: end all of a user's sessions (email)"
echo "12 : RESET-2FA: turn off a user's 2FA       (email)  [recovery]"
echo "13 : EVENTS: recent account security events"
rule
echo CONTENT
echo "20 : DEMO-SEED: seed the demo workspace (idempotent)"
echo "21 : DEMO-CLEAR: remove all demo data            [DESTRUCTIVE]"
echo "22 : CATALOG-SEED: (re)seed the catalog (idempotent)"
echo "23 : CATALOG: counts by type"
rule
echo OPERATIONS
echo "30 : MONITORS: run those that are due"
echo "31 : FAILING: monitors currently failing"
echo "32 : WEBHOOKS: run the silence check"
echo "33 : STATS: platform totals at a glance"
rule
echo MAINTENANCE
echo "90 : PRUNE-HISTORY: delete request history older than N days [DESTRUCTIVE]"
echo "92 : PRUNE-REPORTS: delete reports older than N days        [DESTRUCTIVE]"
echo "93 : QUEUE: list failed jobs"
echo "94 : QUEUE-RETRY: retry all failed jobs"
echo "95 : MAINTENANCE: toggle maintenance mode (down/up)"
echo "91 : HISTORY: show this script's log"
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

  "01" )
  banner "ADMIN ACCOUNTS"
  tink "foreach(\App\Models\User::where('is_admin',true)->get() as \$u){echo str_pad(\$u->email,32).\$u->name.PHP_EOL;}" \
    || echo "  none"
  note "Listed admins"
  ;;

  "02" )
  banner "PROMOTE TO ADMIN"
  if need_email; then
    if confirm "Grant admin to $EMAIL?"; then
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\$u->update(['is_admin'=>true])?'Promoted '.\$u->email:'Failed'):'No such user';"
      note "Promoted $EMAIL to admin"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "03" )
  banner "DEMOTE ADMIN"
  if need_email; then
    if confirm "Remove admin from $EMAIL?"; then
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\$u->update(['is_admin'=>false])?'Demoted '.\$u->email:'Failed'):'No such user';"
      note "Demoted $EMAIL"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "04" )
  banner "RESET USER PASSWORD"
  # Passwords are hashed and cannot be recovered — only replaced.
  if need_email; then
    printf 'New password: '
    read -r NEWPASS
    if [ -z "$NEWPASS" ]; then
      echo "No password entered. Cancelled."
    else
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\$u->forceFill(['password'=>bcrypt('$NEWPASS')])->save()?'Password reset for '.\$u->email:'Failed'):'No such user';"
      note "Reset password for $EMAIL"
    fi
  else echo "No email given."; fi
  ;;

  "05" )
  banner "ADMINS WITHOUT TWO-FACTOR"
  tink "\$a=\App\Models\User::where('is_admin',true)->whereNull('two_factor_confirmed_at')->pluck('email'); echo \$a->isEmpty()?'All admins have 2FA on.':\$a->implode(PHP_EOL);"
  note "Checked admin 2FA coverage"
  ;;

  "06" )
  banner "NEWEST SIGN-UPS"
  tink "foreach(\App\Models\User::latest()->take(10)->get() as \$u){echo str_pad(\$u->created_at->format('Y-m-d H:i'),18).str_pad(\$u->email,32).(\$u->is_admin?'admin':'').PHP_EOL;}"
  note "Listed recent sign-ups"
  ;;

  "07" )
  banner "USER DETAIL"
  if need_email; then
    tink "
      \$u=\App\Models\User::withTrashed()->where('email','$EMAIL')->first();
      if(!\$u){echo 'No such user';return;}
      echo 'Name          '.\$u->name.PHP_EOL;
      echo 'Email         '.\$u->email.(\$u->email_verified_at?' (verified)':' (unverified)').PHP_EOL;
      echo 'Role          '.(\$u->is_admin?'admin':'user').(\$u->is_demo?' · demo':'').(\$u->trashed()?' · DEACTIVATED':'').PHP_EOL;
      echo 'Organisation  '.(\$u->organisation->name??'—').PHP_EOL;
      echo 'Two-factor    '.(\$u->hasTwoFactorEnabled()?'on':'off').PHP_EOL;
      echo 'Sessions      '.\Illuminate\Support\Facades\DB::table('sessions')->where('user_id',\$u->id)->count().PHP_EOL;
      echo 'API keys      '.\$u->apiKeys()->whereNull('revoked_at')->count().' active'.PHP_EOL;
      echo 'Saved reqs    '.\$u->savedRequests()->count().PHP_EOL;
      echo 'Collections   '.\$u->collections()->count().PHP_EOL;
      echo 'Monitors      '.\$u->monitors()->count().PHP_EOL;
      echo 'Requests sent '.\$u->requestHistories()->count().PHP_EOL;
      echo 'Joined        '.\$u->created_at->format('Y-m-d H:i').PHP_EOL;
    "
    note "Looked up $EMAIL"
  else echo "No email given."; fi
  ;;

  "08" )
  banner "DEACTIVATE USER (soft-delete)"
  # Reversible: the account is blocked from signing in and its live sessions
  # are ended, but its data is kept and can be restored (option 09).
  if need_email; then
    if confirm "Deactivate $EMAIL?"; then
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); if(!\$u){echo 'No such user';return;} if(\$u->is_admin){echo 'Refusing: user is an admin.';return;} \$u->delete(); \Illuminate\Support\Facades\DB::table('sessions')->where('user_id',\$u->id)->delete(); echo 'Deactivated '.\$u->email;"
      note "Deactivated $EMAIL"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "09" )
  banner "RESTORE USER"
  if need_email; then
    tink "\$u=\App\Models\User::onlyTrashed()->where('email','$EMAIL')->first(); echo \$u?(\$u->restore()?'Restored '.\$u->email:'Failed'):'No deactivated user with that email';"
    note "Restored $EMAIL"
  else echo "No email given."; fi
  ;;

  "10" )
  banner "FAILED SIGN-INS (24h) BY IP"
  tink "
    \$rows=\App\Models\AuditEvent::where('action','auth.login_failed')->where('created_at','>=',now()->subDay())->whereNotNull('ip')->selectRaw('ip, count(*) c')->groupBy('ip')->orderByDesc('c')->get();
    echo \$rows->isEmpty()?'No failed sign-ins in the last 24h.':\$rows->map(fn(\$r)=>str_pad(\$r->ip,20).\$r->c.' attempt(s)')->implode(PHP_EOL);
  "
  note "Reviewed failed sign-ins"
  ;;

  "11" )
  banner "SIGN A USER OUT EVERYWHERE"
  if need_email; then
    if confirm "End every session for $EMAIL?"; then
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\Illuminate\Support\Facades\DB::table('sessions')->where('user_id',\$u->id)->delete()).' session(s) ended':'No such user';"
      note "Signed out all sessions for $EMAIL"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "12" )
  banner "RESET TWO-FACTOR (recovery)"
  # For a user who has lost their authenticator. They can re-enrol afterwards.
  if need_email; then
    if confirm "Turn off 2FA for $EMAIL?"; then
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\$u->forceFill(['two_factor_secret'=>null,'two_factor_recovery_codes'=>null,'two_factor_confirmed_at'=>null])->save()?'2FA reset for '.\$u->email:'Failed'):'No such user';"
      note "Reset 2FA for $EMAIL"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "13" )
  banner "RECENT SECURITY EVENTS"
  tink "foreach(\App\Models\AuditEvent::latest('id')->take(15)->get() as \$e){echo str_pad(\$e->created_at->format('m-d H:i'),14).str_pad(\$e->action,22).str_pad((string)(\$e->actor_email??'-'),28).(\$e->ip??'').PHP_EOL;}"
  note "Reviewed security events"
  ;;

  "20" )
  banner "SEED DEMO WORKSPACE"
  php artisan db:seed --class=DemoSeeder
  note "Seeded demo workspace"
  ;;

  "21" )
  banner "CLEAR DEMO DATA [DESTRUCTIVE]"
  db_context
  echo "This deletes every demo user (is_demo) and their data."
  if confirm "Remove all demo data?"; then
    php artisan demo:clear --force
    note "Cleared demo data"
  else echo "Cancelled."; fi
  ;;

  "22" )
  banner "SEED CATALOG"
  php artisan db:seed --class=CatalogSeeder
  note "Seeded catalog"
  ;;

  "23" )
  banner "CATALOG COUNTS"
  tink "foreach(\App\Models\CatalogItem::TYPES as \$t){echo str_pad(\$t,12).': '.\App\Models\CatalogItem::where('type',\$t)->count().'  ('.\App\Models\CatalogItem::where('type',\$t)->where('is_active',true)->count().' active)'.PHP_EOL;}"
  note "Catalog counts"
  ;;

  "30" )
  banner "MONITORS: run those that are due"
  php artisan monitors:run
  note "Ran due monitors"
  ;;

  "31" )
  banner "FAILING MONITORS"
  tink "\$m=\App\Models\Monitor::where('last_status','failing')->with('user:id,email')->get(); echo \$m->isEmpty()?'No monitors are failing.':\$m->map(fn(\$x)=>str_pad(\$x->name,28).(\$x->user->email??'?').' · '.\$x->consecutive_failures.' in a row')->implode(PHP_EOL);"
  note "Listed failing monitors"
  ;;

  "32" )
  banner "WEBHOOK SILENCE CHECK"
  php artisan webhooks:check
  note "Ran webhook silence check"
  ;;

  "33" )
  banner "PLATFORM STATS"
  tink "
    echo 'Users            '.\App\Models\User::count().' ('.\App\Models\User::where('is_admin',true)->count().' admin, '.\App\Models\User::where('is_demo',true)->count().' demo)'.PHP_EOL;
    echo 'New this week     '.\App\Models\User::where('created_at','>=',now()->subWeek())->count().PHP_EOL;
    echo 'Requests sent     '.\App\Models\RequestHistory::count().' ('.\App\Models\RequestHistory::where('created_at','>=',now()->subWeek())->count().' this week)'.PHP_EOL;
    echo 'Saved requests    '.\App\Models\SavedRequest::count().PHP_EOL;
    echo 'Collections       '.\App\Models\Collection::count().PHP_EOL;
    echo 'Monitors          '.\App\Models\Monitor::count().' ('.\App\Models\Monitor::where('last_status','failing')->count().' failing)'.PHP_EOL;
    echo 'Reports           '.\App\Models\InspectionReport::count().PHP_EOL;
  "
  note "Viewed platform stats"
  ;;

  "40" )
  banner "ORGANISATIONS"
  tink "\$o=\App\Models\Organisation::withCount('users')->orderBy('name')->get(); echo \$o->isEmpty()?'No organisations.':\$o->map(fn(\$x)=>str_pad(\$x->slug,24).str_pad(\$x->name,28).\$x->users_count.' member(s)')->implode(PHP_EOL); echo PHP_EOL.'Unassigned users: '.\App\Models\User::whereNull('organisation_id')->count();"
  note "Listed organisations"
  ;;

  "41" )
  banner "ASSIGN USER TO ORGANISATION"
  if need_email; then
    SLUG="$3"
    if [ -z "$SLUG" ]; then printf 'Organisation slug: '; read -r SLUG; fi
    if [ -z "$SLUG" ]; then echo "No org slug given."; else
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); \$o=\App\Models\Organisation::where('slug','$SLUG')->first(); if(!\$u){echo 'No such user';return;} if(!\$o){echo 'No such organisation';return;} \$u->update(['organisation_id'=>\$o->id]); echo \$u->email.' -> '.\$o->name;"
      note "Assigned $EMAIL to $SLUG"
    fi
  else echo "No email given."; fi
  ;;

  "42" )
  banner "UNASSIGN USER FROM ORGANISATION"
  if need_email; then
    tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?(\$u->update(['organisation_id'=>null])?'Unassigned '.\$u->email:'Failed'):'No such user';"
    note "Unassigned $EMAIL"
  else echo "No email given."; fi
  ;;

  "90" )
  banner "PRUNE OLD REQUEST HISTORY [DESTRUCTIVE]"
  DAYS="${PARAM2:-30}"
  db_context
  COUNT=$(tink "echo \App\Models\RequestHistory::where('created_at','<',now()->subDays($DAYS))->count();" | tr -dc '0-9')
  echo "Request history entries older than $DAYS days: ${COUNT:-0}"
  if [ "${COUNT:-0}" != "0" ] && confirm "Delete these ${COUNT} entries?"; then
    tink "echo \App\Models\RequestHistory::where('created_at','<',now()->subDays($DAYS))->delete().' deleted';"
    note "Pruned request history older than $DAYS days"
  else
    echo "Nothing pruned."
  fi
  ;;

  "92" )
  banner "PRUNE OLD REPORTS [DESTRUCTIVE]"
  DAYS="${PARAM2:-30}"
  db_context
  # Keep shared reports (a public link points at them); prune the rest.
  COUNT=$(tink "echo \App\Models\InspectionReport::where('created_at','<',now()->subDays($DAYS))->whereNull('share_token')->count();" | tr -dc '0-9')
  echo "Unshared reports older than $DAYS days: ${COUNT:-0}"
  if [ "${COUNT:-0}" != "0" ] && confirm "Delete these ${COUNT} report(s)?"; then
    tink "echo \App\Models\InspectionReport::where('created_at','<',now()->subDays($DAYS))->whereNull('share_token')->delete().' deleted';"
    note "Pruned reports older than $DAYS days"
  else
    echo "Nothing pruned."
  fi
  ;;

  "93" )
  banner "FAILED QUEUE JOBS"
  php artisan queue:failed
  note "Listed failed jobs"
  ;;

  "94" )
  banner "RETRY FAILED QUEUE JOBS"
  if confirm "Retry all failed jobs?"; then
    php artisan queue:retry all
    note "Retried failed jobs"
  else echo "Cancelled."; fi
  ;;

  "95" )
  banner "MAINTENANCE MODE"
  db_context
  if php artisan tinker --execute="echo app()->isDownForMaintenance()?'down':'up';" 2>/dev/null | grep -q down; then
    echo "Currently: DOWN (maintenance mode on)."
    if confirm "Bring the app back UP?"; then php artisan up; note "Maintenance mode OFF"; else echo "Left down."; fi
  else
    echo "Currently: UP."
    if confirm "Put the app into maintenance mode (DOWN)?"; then php artisan down; note "Maintenance mode ON"; else echo "Left up."; fi
  fi
  ;;

  "91" )
  banner "HISTORY"
  if [ -f "$LOG_FILE" ]; then head -30 "$LOG_FILE"; else echo "No history yet."; fi
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
