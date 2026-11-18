#!/usr/bin/env bash
set -euo pipefail

port=${CRM_DEPLOY_PORT:-22}
if [[ ! ${CRM_DEPLOY_HOST:-} =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ ||
      ! ${CRM_DEPLOY_USER:-} =~ ^[a-zA-Z_][a-zA-Z0-9_-]*$ ||
      ! $port =~ ^[0-9]{1,5}$ ||
      ! ${CRM_DEPLOY_ROOT:-} =~ ^(/[a-zA-Z0-9_-][a-zA-Z0-9_.-]*)+$ ||
      ! ${GITHUB_SHA:-} =~ ^[a-f0-9]{40}$ ||
      -z ${CRM_DEPLOY_KEY:-} || -z ${CRM_DEPLOY_KNOWN_HOSTS:-} ]]; then
    echo '::error::Configure the production SSH target, deployment root, SSH key and pinned host key.' >&2
    exit 1
fi

key_dir=$(mktemp -d)
trap 'rm -f -- "$key_dir/key" "$key_dir/known_hosts"; rmdir -- "$key_dir"' EXIT
(
    umask 077
    printf '%s\n' "$CRM_DEPLOY_KEY" > "$key_dir/key"
    printf '%s\n' "$CRM_DEPLOY_KNOWN_HOSTS" > "$key_dir/known_hosts"
)
unset CRM_DEPLOY_KEY CRM_DEPLOY_KNOWN_HOSTS

paths=(.htaccess api assets includes migrations models version.json)
while IFS= read -r path; do
    [[ $path != *.php ]] || paths+=("$path")
done < <(git ls-tree --name-only "$GITHUB_SHA")

# The input stream carries only the verified tree. The remote command is our fixed release script.
remote_script=$(base64 -w0 .github/scripts/activate-release.sh)
command="bash -c \"\$(printf '%s' '$remote_script' | base64 -d)\" -- '$CRM_DEPLOY_ROOT' '$GITHUB_SHA' 'https://app.servis.expert/health.php'"
git archive --format=tar.gz "$GITHUB_SHA" -- "${paths[@]}" |
    ssh -T -o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
        -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o GlobalKnownHostsFile=/dev/null \
        -o "UserKnownHostsFile=$key_dir/known_hosts" -i "$key_dir/key" -p "$port" \
        "$CRM_DEPLOY_USER@$CRM_DEPLOY_HOST" "$command"
