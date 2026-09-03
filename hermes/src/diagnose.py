"""Manual browser-guard diagnostic command."""

from __future__ import annotations

import argparse
import json
import os
import select
import sys
from pathlib import Path

from browser_guard import BrowserGuard, BrowserGuardError
from playwright.sync_api import sync_playwright

STEALTH_JS = r"""
// Override navigator.webdriver
Object.defineProperty(navigator, 'webdriver', {
    get: () => false,
});

// Override navigator.plugins
Object.defineProperty(navigator, 'plugins', {
    get: () => [1, 2, 3, 4, 5],
});

// Override navigator.languages
Object.defineProperty(navigator, 'languages', {
    get: () => ['pt-BR', 'pt', 'en-US', 'en'],
});

// Override chrome.runtime
window.chrome = {
    runtime: {},
    loadTimes: function () {},
    csi: function () {},
    app: {},
};

// Override permissions query
if (navigator.permissions) {
    const originalQuery = navigator.permissions.query.bind(navigator.permissions);
    navigator.permissions.query = (desc) => {
        if (desc.name === 'notifications') {
            return Promise.resolve({ state: 'denied', onchange: null });
        }
        return originalQuery(desc);
    };
}

// Override navigator.webdriver flag in webdriver
if (navigator.webdriver === false) {
    delete navigator.__proto__.webdriver;
}

// Ensure WebGL vendor/renderer look real
const getParameter = WebGLRenderingContext.prototype.getParameter;
WebGLRenderingContext.prototype.getParameter = function (param) {
    if (param === 37445) return 'Intel Inc.';
    if (param === 37446) return 'Intel Iris OpenGL Engine';
    return getParameter.call(this, param);
};
"""


def _resolve_proxy() -> str | None:
    """Read the HTTP proxy from HERMES_HTTP_PROXY env var."""
    return os.environ.get("HERMES_HTTP_PROXY") or None


def _parse_args(
    arguments: list[str],
) -> tuple[str, str | None, bool, bool, bool] | None:
    parser = argparse.ArgumentParser(
        description="Run Browser Guard diagnostics for one URL."
    )
    parser.add_argument("url", nargs="?", help="URL to diagnose")
    parser.add_argument(
        "--url",
        dest="url_option",
        help="URL to diagnose (explicit diagnostic mode)",
    )
    parser.add_argument("--screenshot", help="Path for an optional screenshot")
    parser.add_argument(
        "--headed",
        action="store_true",
        help="Show Chromium on the DISPLAY provided by the runtime",
    )
    parser.add_argument(
        "--keep-open",
        action="store_true",
        help="Keep the browser open after navigation until interrupted",
    )
    parser.add_argument(
        "--chrome",
        action="store_true",
        help="Use the installed Google Chrome channel instead of Playwright Chromium",
    )
    parsed = parser.parse_args(arguments)

    if (parsed.url and parsed.url_option) or not (parsed.url or parsed.url_option):
        return None
    return (
        parsed.url_option or parsed.url,
        parsed.screenshot,
        parsed.headed,
        parsed.keep_open,
        parsed.chrome,
    )


def _blocked_post_report(guard: BrowserGuard) -> tuple[bool, str | None]:
    post_count = sum(
        request["method"] == "POST" for request in guard.blocked_requests
    )
    if not post_count:
        return False, None
    return (
        True,
        f"{post_count} POST request(s) were blocked by read-only mode; "
        "the page may require POST for rendering.",
    )


def _wait_for_interactive_close(page) -> None:
    """Keep Playwright's sync event loop running while the browser is interactive."""
    while True:
        ready, _, _ = select.select([sys.stdin], [], [], 0)
        if ready:
            sys.stdin.readline()
            return
        page.wait_for_timeout(100)


def _diagnostic_summary(
    page,
    response,
    guard: BrowserGuard,
    document_requests: list[dict[str, str]] | None = None,
    failed_requests: list[dict[str, str]] | None = None,
) -> dict:
    body_text = page.locator("body").inner_text(timeout=5000).strip()
    post_blocked, post_note = _blocked_post_report(guard)
    return {
        "final_url": page.url,
        "status": response.status if response else None,
        "title": page.title(),
        "allowed_requests": guard.allowed_request_count,
        "blocked_requests": len(guard.blocked_requests),
        "blocked": guard.blocked_requests,
        "dom_readable": bool(body_text),
        "dom_content_sufficient": bool(body_text),
        "dom_text_length": len(body_text),
        "blocked_post_requests": post_blocked,
        "blocked_post_note": post_note,
        "document_requests": document_requests or [],
        "failed_requests": failed_requests or [],
    }


def _blocked_summary(guard: BrowserGuard, reason: str) -> dict:
    post_blocked, post_note = _blocked_post_report(guard)
    return {
        "final_url": None,
        "status": None,
        "title": None,
        "allowed_requests": guard.allowed_request_count,
        "blocked_requests": len(guard.blocked_requests),
        "blocked": guard.blocked_requests,
        "error": reason,
        "dom_readable": False,
        "dom_content_sufficient": False,
        "dom_text_length": 0,
        "blocked_post_requests": post_blocked,
        "blocked_post_note": post_note,
    }


def main() -> int:
    try:
        parsed_args = _parse_args(sys.argv[1:])
    except SystemExit as exc:
        return int(exc.code)
    if parsed_args is None:
        print(
            f"usage: {sys.argv[0]} [URL | --url URL] [--screenshot PATH] [--headed] [--keep-open]",
            file=sys.stderr,
        )
        return 2

    url, screenshot, headed, keep_open, use_chrome = parsed_args
    print(f"[DEBUG] url={url}, headed={headed}, keep_open={keep_open}, use_chrome={use_chrome}", file=sys.stderr, flush=True)
    guard = BrowserGuard()

    try:
        with sync_playwright() as playwright:
            print("[DEBUG] sync_playwright started", file=sys.stderr, flush=True)
            launch_options = {
                "headless": not headed,
                "channel": "chrome",
                "args": [
                    "--disable-blink-features=AutomationControlled",
                    "--no-sandbox",
                    "--disable-dev-shm-usage",
                    "--disable-gpu",
                    "--no-first-run",
                    "--no-default-browser-check",
                    "--disable-session-crashed-bubble",
                    "--disable-renderer-accessibility",
                    "--disable-software-rasterizer",
                    "--disable-breakpad",
                    "--disable-crash-reporter",
                    "--disable-background-networking",
                    "--disable-background-timer-throttling",
                    "--disable-backgrounding-occluded-windows",
                    "--disable-renderer-backgrounding",
                ],
            }
            if not use_chrome:
                launch_options.pop("channel")
            context_options = {
                "user_agent": (
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/126.0.0.0 Safari/537.36"
                ),
                "locale": "pt-BR",
                "timezone_id": "America/Sao_Paulo",
                "viewport": {"width": 1900, "height": 1060},
            }
            proxy = _resolve_proxy()
            if proxy:
                context_options["proxy"] = {"server": proxy}
            
            # Try persistent context first, fall back to non-persistent if locked
            try:
                print("[DEBUG] Attempting persistent context...", file=sys.stderr, flush=True)
                context = playwright.chromium.launch_persistent_context(
                    os.path.expanduser("~/.config/chromium"),
                    **launch_options,
                    **context_options,
                )
            except Exception as e:
                print(f"[DEBUG] Persistent context failed ({e}), using non-persistent", file=sys.stderr, flush=True)
                browser = playwright.chromium.launch(**launch_options)
                context = browser.new_context(**context_options)
            
            context.set_default_navigation_timeout(60000)
            context.set_default_timeout(30000)
            context.add_init_script(STEALTH_JS)
            print("[DEBUG] context created and stealth applied", file=sys.stderr, flush=True)
            try:
                page = context.new_page()
                print("[DEBUG] new page created", file=sys.stderr, flush=True)
                page.bring_to_front()
                for existing_page in list(context.pages):
                    if existing_page != page:
                        existing_page.close()
                page.bring_to_front()
                page.on(
                    "crash",
                    lambda: print("Chromium page renderer crashed", file=sys.stderr),
                )
                document_requests: list[dict[str, str]] = []
                failed_requests: list[dict[str, str]] = []

                def record_document_request(request) -> None:
                    if request.resource_type == "document":
                        document_requests.append(
                            {"method": request.method, "url": request.url}
                        )

                def record_failed_request(request) -> None:
                    failed_requests.append(
                        {
                            "method": request.method,
                            "resource_type": request.resource_type,
                            "url": request.url,
                            "failure": request.failure or "unknown",
                        }
                    )

                page.on("request", record_document_request)
                page.on("requestfailed", record_failed_request)
                print(f"[DEBUG] About to call safe_navigate for: {url}", file=sys.stderr, flush=True)
                print(f"Starting guarded navigation: {url}", file=sys.stderr, flush=True)
                response = guard.safe_navigate(
                    page,
                    url,
                    wait_until="domcontentloaded",
                    timeout=60000,
                )
                page.wait_for_timeout(2500)
                print(f"Navigation reached: {page.url}", file=sys.stderr, flush=True)
                if screenshot:
                    screenshot_path = Path(screenshot)
                    screenshot_path.parent.mkdir(parents=True, exist_ok=True)
                    page.screenshot(path=str(screenshot_path), full_page=True)
                summary = _diagnostic_summary(
                    page, response, guard, document_requests, failed_requests
                )
                summary["screenshot"] = str(screenshot_path) if screenshot else None
                print(json.dumps(summary, indent=2))
                if keep_open:
                    print("Browser is open; press Ctrl-C to close.", file=sys.stderr)
                    try:
                        _wait_for_interactive_close(page)
                    except KeyboardInterrupt:
                        pass
                    print("Final diagnostic summary:", file=sys.stderr)
                    final_summary = _diagnostic_summary(
                        page, response, guard, document_requests, failed_requests
                    )
                    final_summary["screenshot"] = str(screenshot_path) if screenshot else None
                    print(json.dumps(final_summary, indent=2))
            finally:
                try:
                    context.close()
                except Exception as exc:
                    print(f"Browser context was already closed: {exc}", file=sys.stderr)
    except BrowserGuardError as exc:
        print(json.dumps(_blocked_summary(guard, str(exc)), indent=2))
        return 1
    except Exception as exc:
        print(json.dumps(_blocked_summary(guard, str(exc)), indent=2))
        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
