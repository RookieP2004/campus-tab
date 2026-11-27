#!/bin/sh
# Entrypoint for the web (Vite) container. npm packages are installed here,
# inside the container, into named volumes mounted over every node_modules
# folder, so nothing is written to the Windows drive.
set -eu

cd /workspace

STAMP=node_modules/.campus-tab-lock
if [ ! -f "$STAMP" ] || ! cmp -s package-lock.json "$STAMP" 2>/dev/null; then
    echo "[campus-tab] installing npm packages into the node_modules volumes..."
    if [ -f package-lock.json ]; then
        npm ci --no-audit --no-fund
    else
        npm install --no-audit --no-fund
    fi
    cp package-lock.json "$STAMP" 2>/dev/null || true
fi

exec "$@"
