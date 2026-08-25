#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

addon_version="1.2.1"
addon_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
installer_path=$(realpath -e -- "$0")
action="install"

usage() {
  echo "Usage: $0 [--check|--install|--rollback] [/absolute/path/to/NVS-exchange]" >&2
}

case "${1:-}" in
  --check|--install|--rollback)
    action=${1#--}
    shift
    ;;
esac
[[ $# -le 1 ]] || { usage; exit 64; }

if [[ $# -eq 1 ]]; then
  target=$(realpath -e -- "$1")
else
  declare -a discovered=()
  declare -A seen=()
  add_candidate() {
    local candidate
    candidate=$(realpath -e -- "$1")
    [[ -f "$candidate/lib/Emercoin.php" && -f "$candidate/web/index.php" ]] || return 0
    if [[ -z "${seen[$candidate]:-}" ]]; then
      discovered+=("$candidate")
      seen[$candidate]=1
    fi
  }
  add_candidate "$PWD"
  for search_root in /var/www /opt /srv; do
    [[ -d "$search_root" ]] || continue
    while IFS= read -r source_file; do
      add_candidate "$(dirname -- "$(dirname -- "$source_file")")"
    done < <(find "$search_root" -maxdepth 6 -type f -path '*/lib/Emercoin.php' 2>/dev/null)
  done
  if [[ ${#discovered[@]} -ne 1 ]]; then
    echo "Automatic discovery found ${#discovered[@]} compatible checkouts." >&2
    for candidate in "${discovered[@]}"; do echo "  $candidate" >&2; done
    echo "Pass the intended absolute path explicitly." >&2
    exit 64
  fi
  target=${discovered[0]}
  echo "Detected NVS-exchange checkout: $target"
fi

managed=(
  lib/Emercoin.php
  lib/Slots.php
  web/index.php
  web/edit.php
  web/slot.php
  web/exchange-form.php
  web/exchange-form-slot.php
  web/batch.php
  web/js/ness-qrcode.js
)
required=(
  lib/Emercoin.php lib/Slots.php lib/Container.php
  web/index.php web/edit.php web/slot.php web/exchange-form.php web/exchange-form-slot.php
)

for relative in "${required[@]}"; do
  [[ -f "$target/$relative" ]] || {
    echo "Not a compatible NVS-exchange checkout: missing $target/$relative" >&2
    exit 1
  }
  [[ ! -L "$target/$relative" ]] || {
    echo "Refusing to replace symlink: $target/$relative" >&2
    exit 1
  }
done

state_dir="$target/.nvs-batch-addon"
last_backup_file="$state_dir/last-backup"

restore_backup() {
  local backup=$1 relative destination temporary
  [[ -d "$backup" && "$backup" == "$state_dir/backups/"* ]] || {
    echo "Invalid backup path: $backup" >&2
    return 1
  }
  for relative in "${managed[@]}"; do
    destination="$target/$relative"
    if [[ -f "$backup/files/$relative" ]]; then
      mkdir -p -- "$(dirname -- "$destination")"
      temporary="$(dirname -- "$destination")/.rollback.$(basename -- "$destination").$$"
      cp -p -- "$backup/files/$relative" "$temporary"
      mv -f -- "$temporary" "$destination"
    elif [[ -f "$backup/absent/$relative" ]]; then
      rm -f -- "$destination"
    else
      echo "Backup lacks state for $relative" >&2
      return 1
    fi
  done
}

if [[ "$action" == "rollback" ]]; then
  [[ -r "$last_backup_file" ]] || { echo "No recorded installation to roll back." >&2; exit 1; }
  if command -v flock >/dev/null 2>&1; then
    exec 9>"$state_dir/install.lock"
    flock -n 9 || { echo "Another batch-addon operation is running." >&2; exit 1; }
  fi
  backup=$(<"$last_backup_file")
  restore_backup "$backup"
  rm -f -- "$state_dir/install-info" "$last_backup_file"
  if command -v php >/dev/null 2>&1; then
    php -l "$target/lib/Emercoin.php"
    php -l "$target/lib/Slots.php"
    php -l "$target/web/index.php"
    php -l "$target/web/slot.php"
  fi
  echo "Rolled back NVS batch/UI addon from: $backup"
  exit 0
fi

for command_name in patch php mktemp realpath; do
  command -v "$command_name" >/dev/null 2>&1 || {
    echo "Missing dependency: $command_name" >&2
    exit 1
  }
done
php -r 'exit(PHP_VERSION_ID >= 70100 ? 0 : 1);' || {
  echo "PHP 7.1 or newer is required." >&2
  exit 1
}
php -r 'exit(extension_loaded("dom") && extension_loaded("sodium") ? 0 : 1);' || {
  echo "Missing required PHP extensions: dom and sodium" >&2
  exit 1
}

if [[ -r "$state_dir/install-info" ]] && grep -qx "version=$addon_version" "$state_dir/install-info"; then
  php -l "$target/lib/Emercoin.php"
  php -l "$target/lib/Slots.php"
  php -l "$target/web/batch.php"
  [[ -s "$target/web/js/ness-qrcode.js" ]]
  echo "NVS batch/UI addon $addon_version is already installed and passes basic checks."
  exit 0
fi

stage=$(mktemp -d "${TMPDIR:-/tmp}/nvs-batch-stage.XXXXXX")
committing=0
backup=""
cleanup() { rm -rf -- "$stage"; }
on_exit() {
  local status=$?
  trap - EXIT
  if [[ $status -ne 0 && $committing -eq 1 && -n "$backup" ]]; then
    echo "Installation failed; restoring the untouched originals." >&2
    restore_backup "$backup" || echo "AUTOMATIC ROLLBACK FAILED: restore $backup manually." >&2
  fi
  rm -f -- "$target/lib/".nvs-batch.*.$$ "$target/web/".nvs-batch.*.$$ "$target/web/js/".nvs-batch.*.$$ 2>/dev/null || true
  cleanup
  exit "$status"
}
trap on_exit EXIT

for source_dir in lib decoders modules wallets web; do
  [[ -d "$target/$source_dir" ]] || continue
  mkdir -p "$stage/root/$source_dir"
  while IFS= read -r -d '' source_file; do
    cp -p -- "$source_file" "$stage/root/$source_dir/$(basename -- "$source_file")"
  done < <(find "$target/$source_dir" -maxdepth 1 -type f -name '*.php' -print0)
done

patch --batch --forward --dry-run -d "$stage/root" -p1 < "$addon_dir/batch.patch"
patch --batch --forward --dry-run -d "$stage/root" -p1 < "$addon_dir/ui.patch"
patch --batch --forward -d "$stage/root" -p1 < "$addon_dir/batch.patch"
patch --batch --forward -d "$stage/root" -p1 < "$addon_dir/ui.patch"
patch --batch --forward --dry-run -d "$stage/root" -p1 < "$addon_dir/magic.patch"
patch --batch --forward -d "$stage/root" -p1 < "$addon_dir/magic.patch"
install -m 0644 -- "$addon_dir/web/batch.php" "$stage/root/web/batch.php"
mkdir -p "$stage/root/web/js"
install -m 0644 -- "$addon_dir/web/js/ness-qrcode.js" "$stage/root/web/js/ness-qrcode.js"

for relative in \
  lib/Emercoin.php lib/Slots.php web/index.php web/edit.php web/slot.php \
  web/exchange-form.php web/exchange-form-slot.php web/batch.php; do
  php -l "$stage/root/$relative"
done
smoke_output=$(REQUEST_METHOD=GET php "$stage/root/web/batch.php")
[[ "$smoke_output" == *"POST an XML nameBatch"* ]] || {
  echo "Staged endpoint smoke test failed." >&2
  exit 1
}
[[ $(grep -c 'data-payment-address=' "$stage/root/web/slot.php") -eq 1 ]]
[[ $(grep -c 'data-payment-address=' "$stage/root/web/exchange-form-slot.php") -eq 1 ]]
[[ $(grep -c 'data-magic-link=' "$stage/root/web/slot.php") -eq 1 ]]
grep -q "https://sd.ness.cx/" "$stage/root/web/slot.php"
grep -q 'value="<?= htmlspecialchars($name' "$stage/root/web/index.php"

if [[ "$action" == "check" ]]; then
  echo "Preflight passed. No server files, database, configuration, or service were changed."
  exit 0
fi

for writable in "$target/lib" "$target/web"; do
  [[ -w "$writable" ]] || { echo "Directory is not writable: $writable" >&2; exit 1; }
done
mkdir -p "$state_dir/backups"
if command -v flock >/dev/null 2>&1; then
  exec 9>"$state_dir/install.lock"
  flock -n 9 || { echo "Another batch-addon installation is running." >&2; exit 1; }
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)-$$
backup="$state_dir/backups/$stamp"
for relative in "${managed[@]}"; do
  if [[ -f "$target/$relative" ]]; then
    [[ ! -L "$target/$relative" ]] || { echo "Refusing to replace symlink: $target/$relative" >&2; exit 1; }
    mkdir -p "$backup/files/$(dirname -- "$relative")"
    cp -p -- "$target/$relative" "$backup/files/$relative"
  else
    mkdir -p "$backup/absent/$(dirname -- "$relative")"
    : > "$backup/absent/$relative"
  fi
done

committing=1
for relative in "${managed[@]}"; do
  destination="$target/$relative"
  mkdir -p -- "$(dirname -- "$destination")"
  temporary="$(dirname -- "$destination")/.nvs-batch.$(basename -- "$destination").$$"
  cp -p -- "$stage/root/$relative" "$temporary"
  mv -f -- "$temporary" "$destination"
done

for relative in \
  lib/Emercoin.php lib/Slots.php web/index.php web/edit.php web/slot.php \
  web/exchange-form.php web/exchange-form-slot.php web/batch.php; do
  php -l "$target/$relative"
done
installed_smoke=$(REQUEST_METHOD=GET php "$target/web/batch.php")
[[ "$installed_smoke" == *"POST an XML nameBatch"* ]]

printf '%s\n' "$backup" > "$state_dir/.last-backup.$$"
mv -f -- "$state_dir/.last-backup.$$" "$last_backup_file"
printf 'version=%s\ninstalled_utc=%s\nbackup=%s\n' \
  "$addon_version" "$(date -u +%FT%TZ)" "$backup" > "$state_dir/install-info"
install -m 0644 -- "$addon_dir/QR-LICENSE.txt" "$state_dir/QR-LICENSE.txt"
committing=0

echo "NVS multi-name batch/UI addon $addon_version installed safely."
echo "Backup: $backup"
echo "Rollback: $installer_path --rollback $target"
echo "Apache, PHP-FPM, configuration, wallets, and the slot database were not restarted or modified."
