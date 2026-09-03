#!/usr/bin/env bash

export DISPLAY="${DISPLAY:-:99}"

echo "Starting Xvfb on display $DISPLAY..."
Xvfb "$DISPLAY" -screen 0 1936x1220x24 -ac &
xvfb_pid=$!

# Wait for X server to be ready
for i in {1..30}; do
    if xdpyinfo -display "$DISPLAY" >/dev/null 2>&1; then
        echo "Xvfb is ready"
        break
    fi
    if [ "$i" -eq 30 ]; then
        echo "ERROR: Xvfb failed to start within 30 seconds"
        exit 1
    fi
    sleep 0.5
done

echo "Starting x11vnc..."
x11vnc -display "$DISPLAY" -localhost -forever -shared -nopw -noxdamage -rfbport 5900 &
x11vnc_pid=$!
sleep 2

echo "Starting websockify on port 7900..."
websockify --web=/usr/share/novnc 0.0.0.0:7900 localhost:5900 &
websockify_pid=$!
sleep 2

cleanup() {
    echo "Cleaning up processes..."
    kill "$websockify_pid" "$x11vnc_pid" "$xvfb_pid" 2>/dev/null || true
    wait
}
trap cleanup EXIT INT TERM

echo "Starting Python diagnose script..."
python src/diagnose.py --headed --keep-open --chrome "$@" 2>&1 || echo "Python script exited with code $?"

echo "Python script finished, keeping container alive for noVNC access..."
echo "Connect to: http://localhost:7900/vnc.html"
echo "Press Ctrl+C to stop the container"

# Keep container alive
while true; do
    sleep 60
done
