#!/usr/bin/env bash
set -euo pipefail

# Resolve script directory and go there
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Basic check: config must exist
if [[ ! -f "config/config.php" ]]; then
  echo "[ERROR] config/config.php not found. Create and edit it before running this script." >&2
  exit 1
fi

# Detect docker compose command
if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker compose)
elif docker-compose version >/dev/null 2>&1; then
  COMPOSE_CMD=(docker-compose)
else
  echo "[ERROR] Neither 'docker compose' nor 'docker-compose' is available in PATH." >&2
  exit 1
fi

echo "[INFO] Building Docker image..."
"${COMPOSE_CMD[@]}" build

echo "[INFO] Starting containers in detached mode..."
"${COMPOSE_CMD[@]}" up -d

echo "[INFO] NVS-exchange should now be available on http://localhost:50123/ (if running locally)."
