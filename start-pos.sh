#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

if [ ! -f .env ]; then
  if [ -f .env.example ]; then
    cp .env.example .env
    echo "Created .env from .env.example"
  else
    echo "No .env.example found; please configure your environment variables before running the POS system."
    exit 1
  fi
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "Python 3 is required to serve the front-end demo."
  exit 1
fi

trap 'echo "Stopping POS services..."; kill 0 2>/dev/null || true' EXIT

echo "Starting Bampo Outfit POS..."
python3 -m http.server 8000 --directory "$ROOT_DIR" >/tmp/bampo_frontend.log 2>&1 &

if command -v php >/dev/null 2>&1; then
  php -S localhost:8080 -t api api/index.php >/tmp/bampo_api.log 2>&1 &
  echo "Frontend: http://localhost:8000"
  echo "API: http://localhost:8080/api"
else
  echo "Frontend: http://localhost:8000"
  echo "API: disabled (PHP not installed on this machine)"
fi

echo "Press Ctrl+C to stop all services."
wait
