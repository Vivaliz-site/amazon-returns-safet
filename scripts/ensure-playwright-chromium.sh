#!/usr/bin/env bash
set -euo pipefail

PLAYWRIGHT_BROWSER_VERSION="${PLAYWRIGHT_BROWSER_VERSION:-1.63.0}"
PLAYWRIGHT_BROWSER_CACHE="${PLAYWRIGHT_BROWSER_CACHE:-/home/ubuntu/.cache/ms-playwright-arm64}"

discover_browser() {
    local candidate resolved
    for candidate in \
        "$PLAYWRIGHT_BROWSER_CACHE"/chromium-*/chrome-linux*/chrome \
        "$PLAYWRIGHT_BROWSER_CACHE"/chromium_headless_shell-*/chrome-headless-shell-linux*/chrome-headless-shell \
        /usr/bin/google-chrome-stable \
        /usr/bin/google-chrome \
        /usr/bin/chromium \
        /usr/bin/chromium-browser; do
        [[ -x "$candidate" ]] || continue
        resolved="$(readlink -f "$candidate" 2>/dev/null || true)"
        if [[ "$resolved" == "/usr/bin/snap" || "$resolved" == "/snap/bin/chromium" ]]; then
            continue
        fi
        printf '%s\n' "$candidate"
        return 0
    done
    return 1
}

browser="$(discover_browser || true)"
if [[ -z "$browser" ]]; then
    [[ "$(id -u)" -eq 0 ]] || { echo "root required to bootstrap Chromium" >&2; exit 77; }
    npx_bin="$(command -v npx 2>/dev/null || true)"
    [[ -n "$npx_bin" && -x "$npx_bin" ]] || { echo "npx is required to bootstrap Playwright Chromium" >&2; exit 69; }
    install -d -o ubuntu -g ubuntu -m 0755 "$PLAYWRIGHT_BROWSER_CACHE"
    echo "playwright_chromium_bootstrap=installing version=$PLAYWRIGHT_BROWSER_VERSION" >&2
    runuser -u ubuntu -- env \
        HOME=/home/ubuntu \
        PLAYWRIGHT_BROWSERS_PATH="$PLAYWRIGHT_BROWSER_CACHE" \
        "$npx_bin" -y "playwright@$PLAYWRIGHT_BROWSER_VERSION" install chromium >&2
    browser="$(discover_browser || true)"
fi

[[ -n "$browser" && -x "$browser" ]] || {
    echo "supported non-Snap Chromium browser not installed after bootstrap" >&2
    exit 69
}
printf '%s\n' "$browser"
