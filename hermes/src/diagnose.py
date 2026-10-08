"""Manual browser-guard diagnostic command."""

from __future__ import annotations

import argparse
import json
import os
import select
import sys
from pathlib import Path

from browser_guard import BrowserGuard, BrowserGuardError


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
        help="Show Firefox on the DISPLAY provided by the runtime",
    )
    parser.add_argument(
        "--keep-open",
        action="store_true",
        help="Keep the browser open after navigation until interrupted",
    )
    parsed = parser.parse_args(arguments)

    if (parsed.url and parsed.url_option) or not (parsed.url or parsed.url_option):
        return None
    return (
        parsed.url_option or parsed.url,
        parsed.screenshot,
        parsed.headed,
        parsed.keep_open,
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

    url, screenshot, headed, keep_open = parsed_args
    print(f"[DEBUG] url={url}, headed={headed}, keep_open={keep_open}", file=sys.stderr, flush=True)
    guard = BrowserGuard()

    try:
        from invisible_playwright import InvisiblePlaywright
        with InvisiblePlaywright() as playwright:
            print("[DEBUG] InvisiblePlaywright started", file=sys.stderr, flush=True)
            context_options = {
                "user_agent": (
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) "
                    "Gecko/20100101 Firefox/128.0"
                ),
                "locale": "pt-BR",
                "timezone_id": "America/Sao_Paulo",
                "viewport": {"width": 1900, "height": 1060},
            }
            proxy = _resolve_proxy()
            if proxy:
                context_options["proxy"] = {"server": proxy}
            
            # Use persistent context only when headed (for cookie persistence)
            if headed:
                profile_dir = os.path.expanduser("~/.mozilla/firefox/pricebuddy-hermes")
                os.makedirs(profile_dir, exist_ok=True)
                context = playwright.firefox.launch_persistent_context(
                    profile_dir,
                    headless=not headed,
                    **context_options,
                )
            else:
                # launch() only accepts headless — context args go to new_context()
                browser = playwright.firefox.launch(headless=True)
                context = browser.new_context(**context_options)
            
            print("[DEBUG] context created (invisible_playwright)", file=sys.stderr, flush=True)
            context.set_default_navigation_timeout(60000)
            context.set_default_timeout(30000)
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
                    lambda: print("Firefox page renderer crashed", file=sys.stderr),
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
