#!/usr/bin/env bash
set -eu

export DISPLAY="${DISPLAY:-:99}"

Xvfb "$DISPLAY" -screen 0 1920x1080x24 -ac &
xvfb_pid=$!

until xdpyinfo -display "$DISPLAY" >/dev/null 2>&1; do
    sleep 0.1
done

cleanup() {
    kill "$xvfb_pid" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

exec python src/main.py
