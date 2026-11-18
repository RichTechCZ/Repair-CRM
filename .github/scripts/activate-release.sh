#!/usr/bin/env bash
set -euo pipefail

root=$1
revision=$2
health_url=$3
live="$root/current"
shared="$root/shared"

if [[ ! $root =~ ^(/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)+$ || ! $revision =~ ^[a-f0-9]{40}$ ||
      ! -L $live || ! -d $live || ! -d $root/releases || ! -d $root/logs ||
      ! -r $shared/.env || ! -r $shared/migrations.env || ! -d $shared/uploads ]]; then
    echo 'Production release layout or migration credentials are missing.' >&2
    exit 1
fi

exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
previous=$(readlink "$live")
candidate=$(mktemp -d "$root/releases/$revision.XXXXXXXX")
switch=$(mktemp -d "$root/.switch.XXXXXXXX")
verified=false
cleanup() {
    result=$?
    trap - EXIT
    if [[ $verified == false && $(readlink "$live") == "$candidate" ]]; then
        if ln -s -- "$previous" "$switch/rollback" && mv -Tf -- "$switch/rollback" "$live"; then
            echo 'Restored the previous release after failed verification.' >&2
        else
            echo 'Rollback failed. Restore the previous release on the server.' >&2
            result=1
        fi
    fi
    rm -f -- "$switch/next" "$switch/rollback"
    rmdir -- "$switch"
    exit "$result"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

tar --extract --gzip --no-same-owner --directory "$candidate"
chmod 0755 "$candidate"
printf '{"revision":"%s"}\n' "$revision" > "$candidate/release.json"
ln -s -- "$shared/.env" "$candidate/.env"
ln -s -- "$shared/uploads" "$candidate/uploads"
for folder in temp backup_db; do
    if [[ -d $shared/$folder ]]; then
        ln -s -- "$shared/$folder" "$candidate/$folder"
    fi
done

log=$(mktemp "$root/logs/migration-$revision.XXXXXXXX")
if ! CRM_ENV=production CRM_MIGRATION_ENV="$shared/migrations.env" \
    php -d variables_order=EGPCS "$candidate/run_migrations.php" >"$log" 2>&1; then
    echo 'Migration failed. See the private migration log on the server.' >&2
    exit 1
fi
# Print only the success statuses. Error details stay in the private log.
grep -E '^(OK|SKIPPED|SKIPPED_EXISTING_SCHEMA): [0-9]{3}_[a-zA-Z0-9_]+\.sql$' "$log" || true

if ! CRM_ENV=production php -d variables_order=EGPCS "$candidate/health.php" 2>>"$log" |
    grep -Fq '"status":"ok"'; then
    echo 'The staged release failed its readiness check.' >&2
    exit 1
fi

ln -s -- "$candidate" "$switch/next"
mv -Tf -- "$switch/next" "$live"

for attempt in 1 2 3; do
    if response=$(curl --fail --silent --show-error --max-time 10 --include "$health_url") &&
        printf '%s\n' "$response" | tr -d '\r' | grep -Fxiq "X-CRM-Release: $revision" &&
        [[ $response == *'"status":"ok"'* ]]; then
        verified=true
        echo "Production is serving $revision. Migration and health checks passed."
        exit 0
    fi
    if [[ $attempt != 3 ]]; then sleep 2; fi
done

echo 'Public health check did not confirm the new release.' >&2
exit 1
