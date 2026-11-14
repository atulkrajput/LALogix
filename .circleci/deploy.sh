#!/usr/bin/env bash
# Uploads the built app (vendor/ and public/build/ included) to the server.
#
#   rsync  rsync over SSH, then runs migrations + caches   (used when SSH_HOST is set)
#   ftp    mirror over FTP/FTPS/SFTP with lftp, no commands (used when FTP_HOST is set)
# Set DEPLOY_METHOD=rsync|ftp only to force one when both are configured.
#
# Paths in .circleci/deploy-exclude.txt are never uploaded or deleted on the
# server (.env, storage/, ...). Configure the variables in CircleCI →
# Project Settings → Environment Variables (see README.md).
set -euo pipefail

cd "$(dirname "$0")/.."

EXCLUDE_FILE=.circleci/deploy-exclude.txt
# Only master is ever deployed (the workflow filter does this too; this is a second guard).
if [ -n "${CIRCLE_BRANCH:-}" ] && [ "$CIRCLE_BRANCH" != "master" ]; then
  echo "Branch '$CIRCLE_BRANCH' is not master. Skipping deploy."
  exit 0
fi

# DEPLOY_METHOD wins; otherwise pick from whichever server details are set.
if [ -n "${DEPLOY_METHOD:-}" ]; then
  METHOD="$DEPLOY_METHOD"
elif [ -n "${FTP_HOST:-}" ]; then
  METHOD=ftp
elif [ -n "${SSH_HOST:-}" ]; then
  METHOD=rsync
else
  echo "No deploy target configured. In CircleCI → Project Settings → Environment Variables set either" >&2
  echo "  FTP_HOST, FTP_USER, FTP_PASSWORD           (FTP upload), or" >&2
  echo "  SSH_HOST, SSH_USER, DEPLOY_PATH            (rsync over SSH)." >&2
  echo "If you added them in a Context instead, the deploy job must use that context." >&2
  exit 1
fi
echo "Deploy method: $METHOD"

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
  require FTP_HOST FTP_USER FTP_PASSWORD
  local protocol="${FTP_PROTOCOL:-ftp}"   # ftp | ftps | sftp
  # Upload into the FTP user's home folder unless FTP_PATH names a subfolder.
  local target="${FTP_PATH:-.}"
  target="${target%/}"
  [ -z "$target" ] && target="/"
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

  # Optional certificate pinning: trust exactly this server certificate (the
  # SHA-1 fingerprint lftp prints in its "Certificate verification" error)
  # even if its name doesn't match FTP_HOST. Any other certificate still fails.
  local cert_pin=""
  if [ -n "${FTP_CERT_FINGERPRINT:-}" ]; then
    # Accept it however it was pasted: any case, with or without colons/dashes/
    # spaces, wrapped in quotes or the (...) from lftp's error message.
    local raw hex fp=""
    raw="$(printf '%s' "$FTP_CERT_FINGERPRINT" | tr '[:lower:]' '[:upper:]' | tr -d '[:space:]')"
    # Longest run of hex digits, allowing ':' or '-' between them.
    hex="$(printf '%s' "$raw" | grep -oE '[0-9A-F]+([:-][0-9A-F]+)*' | awk '{ if (length($0) > length(m)) m = $0 } END { print m }' | tr -d ':-')"
    case "${#hex}" in
      40)
        local i
        for ((i = 0; i < 40; i += 2)); do fp="${fp:+$fp:}${hex:i:2}"; done
        ;;
      64)
        echo "FTP_CERT_FINGERPRINT looks like a SHA-256 fingerprint. lftp needs the SHA-1 one: copy the 20 pairs shown in brackets in the deploy's 'Certificate verification' error." >&2
        exit 1
        ;;
      *)
        echo "FTP_CERT_FINGERPRINT must be the SHA-1 fingerprint from the deploy error, 20 hex pairs like 4E:46:DA:...:45 (found ${#hex} hex digits, need 40)." >&2
        exit 1
        ;;
    esac
    cert_pin="set ssl:verify-certificate/$fp no"
    echo "→ Trusting pinned certificate $fp"
  fi

  echo "→ Uploading to $protocol://$FTP_HOST (folder: $target) with lftp"
  export LFTP_PASSWORD="$FTP_PASSWORD"
  lftp <<EOF
set cmd:fail-exit yes
set net:max-retries 3
set net:timeout 30
set ftp:ssl-allow ${FTP_SSL:-yes}
set ssl:verify-certificate ${FTP_VERIFY_CERT:-yes}
$cert_pin
set sftp:auto-confirm yes
open $port_opt --env-password -u "$FTP_USER" $protocol://$FTP_HOST
$mkdirs
mirror --reverse --delete --parallel=4 --verbose=1 $excludes ./ "$target"
bye
EOF
  echo "✓ Uploaded to $FTP_HOST (folder: $target)"
  echo "  FTP cannot run commands: run 'php artisan migrate --force' and 'php artisan optimize' on the server after schema changes."
}

case "$METHOD" in
  rsync) deploy_rsync ;;
  ftp)   deploy_ftp ;;
  *) echo "DEPLOY_METHOD must be 'rsync' or 'ftp' (got '$METHOD')" >&2; exit 1 ;;
esac
