#!/usr/bin/env bash
# Uploads the built app (vendor/ and public/build/ included) to the server.
#
#   DEPLOY_METHOD=rsync (default)  rsync over SSH, then runs migrations + caches
#   DEPLOY_METHOD=ftp              mirror over FTP/FTPS/SFTP with lftp (no remote commands)
#
# Paths in .circleci/deploy-exclude.txt are never uploaded or deleted on the
# server (.env, storage/, ...). Configure the variables in CircleCI →
# Project Settings → Environment Variables (see README.md).
set -euo pipefail

cd "$(dirname "$0")/.."

EXCLUDE_FILE=.circleci/deploy-exclude.txt
METHOD="${DEPLOY_METHOD:-rsync}"

require() {
  for name in "$@"; do
    if [ -z "${!name:-}" ]; then
      echo "Missing CircleCI environment variable: $name" >&2
      exit 1
    fi
  done
}

# Folders Laravel needs to exist on the server (storage/ itself is never uploaded).
STORAGE_DIRS="storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache"

deploy_rsync() {
  require SSH_HOST SSH_USER DEPLOY_PATH
  local port="${SSH_PORT:-22}"
  local php="${PHP_BIN:-php}"
  local target="${DEPLOY_PATH%/}"

  mkdir -p ~/.ssh
  ssh-keyscan -p "$port" -H "$SSH_HOST" >> ~/.ssh/known_hosts 2>/dev/null
  local ssh_cmd="ssh -p $port -o BatchMode=yes"

  echo "→ Preparing $SSH_USER@$SSH_HOST:$target"
  $ssh_cmd "$SSH_USER@$SSH_HOST" "mkdir -p '$target' && cd '$target' && mkdir -p $STORAGE_DIRS"

  echo "→ Uploading with rsync"
  rsync -az --delete --stats \
    --exclude-from="$EXCLUDE_FILE" \
    -e "$ssh_cmd" \
    ./ "$SSH_USER@$SSH_HOST:$target/"

  echo "→ Running post-deploy commands"
  $ssh_cmd "$SSH_USER@$SSH_HOST" bash -s <<EOF
set -e
cd '$target'
if [ ! -f .env ]; then
  echo "No .env on the server in $target. Create it from .env.example, then re-run the deploy." >&2
  exit 1
fi
$php artisan migrate --force
$php artisan optimize
$php artisan storage:link >/dev/null 2>&1 || true
EOF
  echo "✓ Deployed to $SSH_HOST:$target"
}

deploy_ftp() {
  require FTP_HOST FTP_USER FTP_PASSWORD FTP_PATH
  local protocol="${FTP_PROTOCOL:-ftp}"   # ftp | ftps | sftp
  local target="${FTP_PATH%/}"
  local port_opt=""
  [ -n "${FTP_PORT:-}" ] && port_opt="-p $FTP_PORT"

  # Turn the exclude list into anchored lftp --exclude regexes, so "storage/"
  # only matches the root storage/ folder, not e.g. vendor/**/storage/.
  # lftp matches directories with a trailing slash, so "dir/" covers its contents.
  local excludes=""
  while IFS= read -r line; do
    line="${line%%#*}"; line="$(echo "$line" | xargs)"
    [ -z "$line" ] && continue
    local rx="${line#/}"
    rx="${rx//./\\.}"        # literal dots
    rx="${rx//\*/[^/]*}"     # * matches within one path segment
    case "$rx" in */) rx="^$rx" ;; *) rx="^$rx\$" ;; esac
    excludes="$excludes --exclude '$rx'"
  done < "$EXCLUDE_FILE"

  local mkdirs=""
  for dir in $STORAGE_DIRS; do
    mkdirs="${mkdirs}mkdir -p -f \"$target/$dir\""$'\n'
  done

  echo "→ Uploading to $protocol://$FTP_HOST$target with lftp"
  export LFTP_PASSWORD="$FTP_PASSWORD"
  lftp <<EOF
set cmd:fail-exit yes
set net:max-retries 3
set net:timeout 30
set ftp:ssl-allow ${FTP_SSL:-yes}
set ssl:verify-certificate ${FTP_VERIFY_CERT:-yes}
set sftp:auto-confirm yes
open $port_opt --env-password -u "$FTP_USER" $protocol://$FTP_HOST
$mkdirs
mirror --reverse --delete --parallel=4 --verbose=1 $excludes ./ "$target"
bye
EOF
  echo "✓ Uploaded to $FTP_HOST:$target"
  echo "  FTP cannot run commands: run 'php artisan migrate --force' and 'php artisan optimize' on the server after schema changes."
}

case "$METHOD" in
  rsync) deploy_rsync ;;
  ftp)   deploy_ftp ;;
  *) echo "DEPLOY_METHOD must be 'rsync' or 'ftp' (got '$METHOD')" >&2; exit 1 ;;
esac
