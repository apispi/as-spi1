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
#   CX_YES=1 ./cx.sh 92  auto-approve confirmations (unattended runs)
#
# Version 1.3
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
echo "43 : OWNER: set an org's owner            (org-slug email)"
rule
echo WORKSPACES
echo "44 : WORKSPACE: members + invites for a user's team (email)"
echo "45 : INVITES: pending workspace invitations platform-wide"
echo "46 : INVITE-KILL: revoke pending invites to an address (email)"
echo "47 : INVITE-SWEEP: delete expired invitations     [DESTRUCTIVE]"
echo "48 : ACTIVITY: recent workspace activity          (email|blank=all)"
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
echo "24 : REPORTS: inspection reports by type"
echo "25 : BACKUP: export a user's workspace to ./log  (email)"
rule
echo OPERATIONS
echo "30 : MONITORS: run those that are due"
echo "31 : FAILING: monitors currently failing"
echo "32 : WEBHOOKS: run the silence check"
echo "33 : STATS: platform totals at a glance"
echo "34 : MAIL-TEST: send a test email             (email)"
rule
echo MONITORING
echo "35 : MONITORS-LIST: every monitor, with type and state"
echo "36 : MUTED: monitors with alerts muted (flags stale mutes)"
echo "37 : UNMUTE: clear a mute                     (id|all)"
echo "38 : SCHEDULER: is schedule:run actually firing?"
rule
echo CREDENTIALS
echo "50 : KEYS: API keys, with scopes and last use"
echo "51 : KEY-REVOKE: revoke a user's API keys    (email)"
echo "52 : AUTH-SCHEMES: which auth schemes are in use"
echo "53 : TOKEN-CACHE: clear cached OAuth access tokens"
rule
echo DIAGNOSTICS
echo "54 : HEALTH: configuration and background-work sanity check"
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

  "43" )
  banner "SET ORGANISATION OWNER"
  SLUG="$PARAM2"
  if [ -z "$SLUG" ]; then printf 'Organisation slug: '; read -r SLUG; fi
  OWNER="$3"
  if [ -z "$OWNER" ]; then printf 'Owner email: '; read -r OWNER; fi
  if [ -z "$SLUG" ] || [ -z "$OWNER" ]; then echo "Need both an org slug and an email."; else
    tink "\$o=\App\Models\Organisation::where('slug','$SLUG')->first(); \$u=\App\Models\User::where('email','$OWNER')->first(); if(!\$o){echo 'No such organisation';return;} if(!\$u){echo 'No such user';return;} if(\$u->organisation_id!==\$o->id){echo 'That user is not a member of '.\$o->name;return;} \$o->update(['owner_user_id'=>\$u->id]); echo 'Owner of '.\$o->name.' is now '.\$u->email;"
    note "Set owner of $SLUG to $OWNER"
  fi
  ;;

  "44" )
  banner "WORKSPACE FOR A USER"
  if need_email; then
    tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); if(!\$u){echo 'No such user';return;} \$o=\$u->organisation; echo 'Workspace     '.(\$o?\$o->name.' ('.\$o->slug.')':'solo - no organisation').PHP_EOL; \$ownerId=\$o?\$o->ownerId():\$u->id; echo 'Owner         '.(\App\Models\User::find(\$ownerId)?->email ?? '-').PHP_EOL; echo 'Members'.PHP_EOL; foreach(\App\Models\User::whereIn('id',\$u->workspaceUserIds())->orderBy('id')->get() as \$m){echo '  '.str_pad(\$m->email,34).(\$m->id===\$ownerId?'owner':'').PHP_EOL;} if(\$o){\$p=\App\Models\WorkspaceInvitation::where('organisation_id',\$o->id)->pending()->get(); echo 'Pending invites'.PHP_EOL; echo \$p->isEmpty()?'  none':\$p->map(fn(\$i)=>'  '.str_pad(\$i->email,34).'expires '.\$i->expires_at->format('Y-m-d'))->implode(PHP_EOL);}"
    note "Inspected workspace for $EMAIL"
  else echo "No email given."; fi
  ;;

  "45" )
  banner "PENDING WORKSPACE INVITATIONS"
  tink "\$i=\App\Models\WorkspaceInvitation::pending()->with(['organisation:id,name','invitedBy:id,email'])->orderBy('id')->get(); echo \$i->isEmpty()?'No pending invitations.':\$i->map(fn(\$x)=>str_pad(\$x->email,34).str_pad(\$x->organisation?->name ?? '-',22).'by '.str_pad(\$x->invitedBy?->email ?? '-',30).'expires '.\$x->expires_at->format('Y-m-d'))->implode(PHP_EOL); echo PHP_EOL.'Expired, not swept: '.\App\Models\WorkspaceInvitation::whereNull('accepted_at')->where('expires_at','<=',now())->count();"
  note "Listed pending invitations"
  ;;

  "46" )
  banner "REVOKE INVITATIONS TO AN ADDRESS"
  # For a mistaken invite, or one sent to someone who has left the company.
  if need_email; then
    COUNT=$(tink "echo \App\Models\WorkspaceInvitation::pending()->whereRaw('LOWER(email) = ?',[strtolower('$EMAIL')])->count();" | tr -dc '0-9')
    echo "Pending invitations to $EMAIL: ${COUNT:-0}"
    if [ "${COUNT:-0}" != "0" ] && confirm "Revoke them? The links stop working immediately."; then
      tink "echo \App\Models\WorkspaceInvitation::pending()->whereRaw('LOWER(email) = ?',[strtolower('$EMAIL')])->delete().' revoked';"
      note "Revoked invitations to $EMAIL"
    else echo "Nothing revoked."; fi
  else echo "No email given."; fi
  ;;

  "47" )
  banner "SWEEP EXPIRED INVITATIONS [DESTRUCTIVE]"
  db_context
  COUNT=$(tink "echo \App\Models\WorkspaceInvitation::whereNull('accepted_at')->where('expires_at','<=',now())->count();" | tr -dc '0-9')
  echo "Expired, unaccepted invitations: ${COUNT:-0}"
  if [ "${COUNT:-0}" != "0" ] && confirm "Delete them? They already cannot be accepted."; then
    tink "echo \App\Models\WorkspaceInvitation::whereNull('accepted_at')->where('expires_at','<=',now())->delete().' deleted';"
    note "Swept expired invitations"
  else echo "Nothing swept."; fi
  ;;

  "48" )
  banner "RECENT WORKSPACE ACTIVITY"
  if [ -n "$PARAM2" ]; then
    tink "\$u=\App\Models\User::where('email','$PARAM2')->first(); if(!\$u){echo 'No such user';return;} \$a=\App\Models\WorkspaceActivity::inWorkspaceOf(\$u)->with('actor:id,email')->latest('id')->take(25)->get(); echo \$a->isEmpty()?'No activity.':\$a->map(fn(\$x)=>str_pad(\$x->created_at->format('Y-m-d H:i'),18).str_pad(\$x->actor?->email ?? '-',30).str_pad(\$x->action,9).\$x->subjectLabel().' '.\$x->subject_name)->implode(PHP_EOL);"
    note "Listed workspace activity for $PARAM2"
  else
    tink "\$a=\App\Models\WorkspaceActivity::with('actor:id,email')->latest('id')->take(25)->get(); echo \$a->isEmpty()?'No activity.':\$a->map(fn(\$x)=>str_pad(\$x->created_at->format('Y-m-d H:i'),18).str_pad(\$x->actor?->email ?? '-',30).str_pad(\$x->action,9).\$x->subjectLabel().' '.\$x->subject_name)->implode(PHP_EOL);"
    note "Listed recent workspace activity"
  fi
  ;;

  "24" )
  banner "INSPECTION REPORTS BY TYPE"
  tink "\$r=\App\Models\InspectionReport::selectRaw('type, count(*) as c')->groupBy('type')->orderByDesc('c')->get(); echo \$r->isEmpty()?'No reports.':\$r->map(fn(\$x)=>str_pad(\$x->type,22).\$x->c)->implode(PHP_EOL); echo PHP_EOL.'Shared (public link): '.\App\Models\InspectionReport::whereNotNull('share_token')->count();"
  note "Listed reports by type"
  ;;

  "34" )
  banner "SEND A TEST EMAIL"
  # Proves the mailer actually works, before someone reports that invitations
  # or monitor alerts never arrive.
  if need_email; then
    echo "Mailer: $(tink "echo config('mail.default');")"
    if confirm "Send a test email to $EMAIL?"; then
      tink "\Illuminate\Support\Facades\Mail::raw('Test email from the Spi admin console at '.now()->toDateTimeString().'. If you can read this, outbound mail works.', fn(\$m)=>\$m->to('$EMAIL')->subject('Spi test email')); echo 'Sent (check the inbox, or the log mailer output).';"
      note "Sent test email to $EMAIL"
    else echo "Cancelled."; fi
  else echo "No email given."; fi
  ;;

  "50" )
  banner "API KEYS"
  tink "\$k=\App\Models\ApiKey::with('user:id,email')->orderByDesc('id')->take(30)->get(); echo \$k->isEmpty()?'No API keys.':\$k->map(function(\$x){\$state=\$x->revoked_at?'revoked':((\$x->expires_at&&\$x->expires_at->isPast())?'expired':'active'); \$scopes=empty(\$x->scopes)?'full access':implode('+',\$x->scopes); return str_pad(\$x->user?->email ?? '-',30).str_pad(\$x->name,22).str_pad(\$state,9).str_pad(\$scopes,22).'last used '.(\$x->last_used_at?\$x->last_used_at->format('Y-m-d'):'never');})->implode(PHP_EOL);"
  note "Listed API keys"
  ;;

  "51" )
  banner "REVOKE A USER'S API KEYS"
  if need_email; then
    COUNT=$(tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \$u?\App\Models\ApiKey::where('user_id',\$u->id)->whereNull('revoked_at')->count():0;" | tr -dc '0-9')
    echo "Active keys for $EMAIL: ${COUNT:-0}"
    if [ "${COUNT:-0}" != "0" ] && confirm "Revoke them all? Anything using them stops working immediately."; then
      # Revoked, not deleted, so the last-used history and audit trail survive.
      tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); echo \App\Models\ApiKey::where('user_id',\$u->id)->whereNull('revoked_at')->update(['revoked_at'=>now()]).' revoked';"
      note "Revoked API keys for $EMAIL"
    else echo "Nothing revoked."; fi
  else echo "No email given."; fi
  ;;

  "52" )
  banner "AUTH SCHEMES IN USE"
  tink "\$tally=function(\$rows){\$t=[]; foreach(\$rows as \$a){\$s=is_array(\$a)?(\$a['scheme'] ?? 'none'):'none'; \$t[\$s]=(\$t[\$s] ?? 0)+1;} return \$t;}; \$r=\$tally(\App\Models\SavedRequest::whereNotNull('auth')->pluck('auth')); \$e=\$tally(\App\Models\Environment::whereNotNull('auth')->pluck('auth')); echo 'Saved requests with their own auth'.PHP_EOL; echo (empty(\$r)?'  none':collect(\$r)->map(fn(\$c,\$s)=>'  '.str_pad(\$s,30).\$c)->implode(PHP_EOL)).PHP_EOL; echo 'Environments supplying auth'.PHP_EOL; echo (empty(\$e)?'  none':collect(\$e)->map(fn(\$c,\$s)=>'  '.str_pad(\$s,30).\$c)->implode(PHP_EOL)).PHP_EOL; echo 'Requests inheriting (no auth of their own): '.\App\Models\SavedRequest::whereNull('auth')->count();"
  note "Listed auth schemes in use"
  ;;

  "53" )
  banner "CLEAR CACHED OAUTH ACCESS TOKENS"
  # OAuth tokens are cached under hashed keys with no scannable prefix, so
  # there is no way to drop only those - this flushes the whole cache.
  echo "Cache store: $(tink "echo config('cache.default');")"
  echo "This flushes the ENTIRE application cache, not only OAuth tokens."
  if confirm "Flush the cache?"; then
    php artisan cache:clear
    note "Flushed the application cache"
  else echo "Cancelled."; fi
  ;;

  "25" )
  banner "BACKUP A WORKSPACE"
  # The same bundle the Workspace screen exports, written to ./log. Secret
  # values and credentials are stripped by the exporter, so a backup is safe
  # to copy around — and restoring one means re-entering them.
  if need_email; then
    tink "\$u=\App\Models\User::where('email','$EMAIL')->first(); if(!\$u){echo 'No such user';return;} \$b=app(\App\Services\Export\WorkspaceBundle::class)->export(\$u); \$slug=\$u->organisation?->slug ?? 'solo-'.\$u->id; \$path='log/backup-'.\$slug.'-'.now()->format('Y-m-d-His').'.spi-workspace.json'; file_put_contents(\$path, json_encode(\$b, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); echo 'Wrote '.\$path.PHP_EOL; echo count(\$b['saved_requests']).' request(s), '.count(\$b['collections']).' collection(s), '.count(\$b['environments']).' environment(s)'.PHP_EOL; echo 'Credentials are NOT included.';"
    note "Backed up the workspace of $EMAIL"
  else echo "No email given."; fi
  ;;

  "35" )
  banner "MONITORS"
  tink "\$m=\App\Models\Monitor::with('user:id,email')->orderBy('id')->get(); echo \$m->isEmpty()?'No monitors.':\$m->map(function(\$x){ \$state=!\$x->is_enabled?'paused':(\$x->isSnoozed()?'muted':\$x->last_status); return str_pad(\$x->id,5).str_pad(mb_substr(\$x->name,0,26),28).str_pad(\$x->type,15).str_pad(\$state,9).str_pad(\$x->interval_minutes.'m',6).'last '.(\$x->last_run_at?\$x->last_run_at->diffForHumans():'never'); })->implode(PHP_EOL); echo PHP_EOL.'Enabled: '.\App\Models\Monitor::where('is_enabled',true)->count().'  failing: '.\App\Models\Monitor::where('last_status','failing')->count();"
  note "Listed monitors"
  ;;

  "36" )
  banner "MONITORS WITH ALERTS MUTED"
  # A forgotten mute is a monitor that runs, fails, and tells nobody — worth
  # looking at deliberately rather than discovering after an incident.
  tink "\$m=\App\Models\Monitor::whereNotNull('snoozed_until')->with('user:id,email')->orderBy('snoozed_until')->get(); if(\$m->isEmpty()){echo 'No mutes set.';return;} \$active=\$m->filter(fn(\$x)=>\$x->isSnoozed()); \$expired=\$m->reject(fn(\$x)=>\$x->isSnoozed()); echo 'Currently muted'.PHP_EOL; echo (\$active->isEmpty()?'  none':\$active->map(fn(\$x)=>'  '.str_pad(\$x->id,5).str_pad(mb_substr(\$x->name,0,26),28).str_pad(\$x->last_status,9).'until '.\$x->snoozed_until->format('Y-m-d H:i').' ('.\$x->snoozed_until->diffForHumans().')'.(\$x->last_status==='failing'?'  <-- FAILING SILENTLY':''))->implode(PHP_EOL)).PHP_EOL; echo 'Mutes that have expired (alerting again)'.PHP_EOL; echo (\$expired->isEmpty()?'  none':\$expired->map(fn(\$x)=>'  '.str_pad(\$x->id,5).str_pad(mb_substr(\$x->name,0,26),28).'expired '.\$x->snoozed_until->diffForHumans())->implode(PHP_EOL));"
  note "Listed muted monitors"
  ;;

  "37" )
  banner "UNMUTE A MONITOR"
  TARGET="$PARAM2"
  if [ -z "$TARGET" ]; then printf 'Monitor id, or "all": '; read -r TARGET; fi
  if [ -z "$TARGET" ]; then echo "Nothing given."; else
    if [ "$TARGET" = "all" ]; then
      COUNT=$(tink "echo \App\Models\Monitor::whereNotNull('snoozed_until')->where('snoozed_until','>',now())->count();" | tr -dc '0-9')
      echo "Monitors currently muted: ${COUNT:-0}"
      if [ "${COUNT:-0}" != "0" ] && confirm "Unmute all of them? Alerting resumes on the next run."; then
        tink "echo \App\Models\Monitor::whereNotNull('snoozed_until')->update(['snoozed_until'=>null]).' unmuted';"
        note "Unmuted all monitors"
      else echo "Nothing unmuted."; fi
    else
      tink "\$m=\App\Models\Monitor::find((int)'$TARGET'); if(!\$m){echo 'No monitor with that id';return;} \$m->update(['snoozed_until'=>null]); echo 'Unmuted '.\$m->name.' (alerting resumes on its next run)';"
      note "Unmuted monitor $TARGET"
    fi
  fi
  ;;

  "38" )
  banner "SCHEDULER HEALTH"
  # Monitors are only as good as the cron entry running schedule:run. If that
  # stops, nothing fails loudly — the checks just quietly stop happening.
  tink "\$m=\App\Models\Monitor::where('is_enabled',true)->get(); if(\$m->isEmpty()){echo 'No enabled monitors to judge by.';return;} \$last=\$m->max('last_run_at'); echo 'Enabled monitors      '.\$m->count().PHP_EOL; echo 'Last run of any       '.(\$last?\$last->format('Y-m-d H:i').' ('.\$last->diffForHumans().')':'never').PHP_EOL; \$never=\$m->whereNull('last_run_at'); echo 'Never run             '.\$never->count().PHP_EOL; \$overdue=\$m->filter(fn(\$x)=>\$x->last_run_at && \$x->last_run_at->addMinutes(\$x->interval_minutes*2)->isPast()); echo 'Overdue (2x interval) '.\$overdue->count().PHP_EOL; if(\$overdue->isNotEmpty()){echo \$overdue->map(fn(\$x)=>'  '.str_pad(mb_substr(\$x->name,0,28),30).'every '.\$x->interval_minutes.'m, last '.\$x->last_run_at->diffForHumans())->implode(PHP_EOL).PHP_EOL;} echo PHP_EOL.((\$last===null || \$last->diffInMinutes(now())>15) ? 'VERDICT: schedule:run does not look like it is firing. Check the cron entry.' : 'VERDICT: the scheduler looks healthy.');"
  note "Checked scheduler health"
  ;;

  "54" )
  banner "HEALTH CHECK"
  tink "
    \$rows=[];
    \$rows[]=['Environment', config('app.env')];
    \$rows[]=['Debug mode', config('app.debug')?'ON':'off'];
    \$rows[]=['URL', config('app.url')];
    \$rows[]=['Database', config('database.default').' / '.\Illuminate\Support\Facades\DB::connection()->getDatabaseName()];
    \$rows[]=['Mailer', config('mail.default')];
    \$rows[]=['Cache store', config('cache.default')];
    \$rows[]=['Queue', config('queue.default')];
    \$rows[]=['Failed jobs', (string)\Illuminate\Support\Facades\DB::table('failed_jobs')->count()];
    \$rows[]=['Users', (string)\App\Models\User::count()];
    \$rows[]=['Enabled monitors', (string)\App\Models\Monitor::where('is_enabled',true)->count()];
    \$rows[]=['Muted monitors', (string)\App\Models\Monitor::whereNotNull('snoozed_until')->where('snoozed_until','>',now())->count()];
    \$last=\App\Models\Monitor::max('last_run_at');
    \$rows[]=['Last monitor run', \$last ? \Illuminate\Support\Carbon::parse(\$last)->diffForHumans() : 'never'];
    \$rows[]=['Pending invitations', (string)\App\Models\WorkspaceInvitation::pending()->count()];
    foreach(\$rows as \$r){ echo str_pad(\$r[0],22).\$r[1].PHP_EOL; }
    \$warn=[];
    if(config('app.env')==='production' && config('app.debug')) \$warn[]='Debug mode is ON in production - it leaks stack traces and config.';
    if(config('mail.default')==='log') \$warn[]='Mail is going to the log, so no email actually reaches anyone.';
    if(\Illuminate\Support\Facades\DB::table('failed_jobs')->count()>0) \$warn[]='There are failed queue jobs (see option 93).';
    if(\$last===null || \Illuminate\Support\Carbon::parse(\$last)->diffInMinutes(now())>15) \$warn[]='No monitor has run recently - check the schedule:run cron entry (option 38).';
    echo PHP_EOL.(\$warn===[] ? 'No warnings.' : 'WARNINGS'.PHP_EOL.'  - '.implode(PHP_EOL.'  - ', \$warn));
  "
  note "Ran the health check"
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
