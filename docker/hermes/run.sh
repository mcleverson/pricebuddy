#!/usr/bin/env bash
set -eu

export DISPLAY="${DISPLAY:-:99}"

cleanup() {
    if [ -n "${xvfb_pid:-}" ]; then
        kill "$xvfb_pid" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

display_number="${DISPLAY#:}"
for attempt in $(seq 0 10); do
    candidate_display=":$((display_number + attempt))"
    Xvfb "$candidate_display" -screen 0 1920x1080x24 -ac &
    xvfb_pid=$!

    for _ in $(seq 1 100); do
        if xdpyinfo -display "$candidate_display" >/dev/null 2>&1; then
            export DISPLAY="$candidate_display"
            break 2
        fi

        if ! kill -0 "$xvfb_pid" 2>/dev/null; then
            break
        fi

        sleep 0.1
    done

    kill "$xvfb_pid" 2>/dev/null || true
    wait "$xvfb_pid" 2>/dev/null || true
    xvfb_pid=""
done

if ! xdpyinfo -display "$DISPLAY" >/dev/null 2>&1; then
    echo "Could not start Xvfb on displays ${display_number}-$((display_number + 10))" >&2
    exit 1
fi

exec python src/main.py
