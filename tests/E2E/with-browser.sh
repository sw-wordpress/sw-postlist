#!/usr/bin/env bash
# Run a browser check with Playwright controlling a disposable Chromium Docker container.
set -euo pipefail
NAME="swp-test-browser-$$"
PORT="${SWP_BROWSER_PORT:-9335}"
IMAGE="${SWP_BROWSER_IMAGE:-chromedp/headless-shell:151.0.7922.109}"
docker run --rm -d --network host --name "$NAME" "$IMAGE" \
  --no-sandbox --remote-debugging-address=0.0.0.0 --remote-debugging-port="$PORT" >/dev/null
trap 'docker stop "$NAME" >/dev/null 2>&1 || true' EXIT
export SWP_BROWSER_CDP="http://127.0.0.1:$PORT"
for _ in $(seq 1 30); do
  if curl --silent --fail "$SWP_BROWSER_CDP/json/version" >/dev/null; then break; fi
  sleep 1
done
curl --silent --fail "$SWP_BROWSER_CDP/json/version" >/dev/null
"$@"
