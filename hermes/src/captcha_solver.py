"""2Captcha solver for Hermes CAPTCHA fallback.

Integrates with 2Captcha.com to solve reCAPTCHA v2, v3, hCaptcha, and
Cloudflare Turnstile challenges encountered during browsing.

Usage:
    solver = CaptchaSolver()
    if solver.enabled:
        token = solver.solve(page, sitekey, page_url, captcha_type="recaptcha")
        if token:
            await page.evaluate(f"document.getElementById('g-recaptcha-response').innerHTML='{token}'")
"""

from __future__ import annotations

import logging
import os
import time
from typing import Any

try:
    from twocaptcha import TwoCaptcha as _TwoCaptcha
    _HAS_TWOCAPTCHA = True
except ImportError:
    _HAS_TWOCAPTCHA = False

logger = logging.getLogger(__name__)


class CaptchaSolver:
    """Wrapper around 2Captcha API for solving CAPTCHAs in-browser."""

    def __init__(self) -> None:
        self.api_key = os.environ.get("HERMES_2CAPTCHA_API_KEY", "").strip()
        self.proxy = os.environ.get("HERMES_2CAPTCHA_PROXY", "").strip() or None
        self.enabled = _HAS_TWOCAPTCHA and bool(self.api_key)
        self.timeout = int(os.environ.get("HERMES_2CAPTCHA_TIMEOUT", "180"))
        self.max_retries = int(os.environ.get("HERMES_2CAPTCHA_MAX_RETRIES", "2"))
        self.solver: Any | None = None
        if self.enabled:
            try:
                kwargs = {}
                if self.proxy:
                    kwargs["proxy"] = {"type": "HTTP", "uri": self.proxy}
                self.solver = _TwoCaptcha(self.api_key, **kwargs)
                logger.info("2Captcha solver enabled (api_key=%s...)", self.api_key[:6])
            except Exception as exc:
                logger.warning("Failed to initialize 2Captcha solver: %s", exc)
                self.enabled = False

    def solve_recaptcha(self, page, sitekey: str, page_url: str,
                        version: int = 2, **kwargs) -> str | None:
        """Solve a reCAPTCHA v2 or v3 challenge and return the token."""
        if not self.enabled:
            return None
        for attempt in range(self.max_retries):
            try:
                result = self.solver.recaptcha(
                    sitekey=sitekey,
                    url=page_url,
                    v=version,
                    timeout=self.timeout,
                    **kwargs,
                )
                token = result.get("code")
                if token:
                    logger.info("reCAPTCHA solved on attempt %d", attempt + 1)
                    return token
                logger.warning("reCAPTCHA returned no code on attempt %d", attempt + 1)
            except Exception as exc:
                logger.warning(
                    "reCAPTCHA solve failed on attempt %d: %s",
                    attempt + 1, exc,
                )
            if attempt < self.max_retries - 1:
                time.sleep(2 * (attempt + 1))
        return None

    def solve_hcaptcha(self, page, sitekey: str, page_url: str) -> str | None:
        """Solve an hCaptcha challenge and return the token."""
        if not self.enabled:
            return None
        for attempt in range(self.max_retries):
            try:
                result = self.solver.hcaptcha(
                    sitekey=sitekey,
                    url=page_url,
                    timeout=self.timeout,
                )
                token = result.get("code")
                if token:
                    logger.info("hCaptcha solved on attempt %d", attempt + 1)
                    return token
                logger.warning("hCaptcha returned no code on attempt %d", attempt + 1)
            except Exception as exc:
                logger.warning(
                    "hCaptcha solve failed on attempt %d: %s",
                    attempt + 1, exc,
                )
            if attempt < self.max_retries - 1:
                time.sleep(2 * (attempt + 1))
        return None

    def solve_turnstile(self, page, sitekey: str, page_url: str) -> str | None:
        """Solve a Cloudflare Turnstile challenge and return the token."""
        if not self.enabled:
            return None
        for attempt in range(self.max_retries):
            try:
                result = self.solver.turnstile(
                    sitekey=sitekey,
                    url=page_url,
                    timeout=self.timeout,
                )
                token = result.get("code")
                if token:
                    logger.info("Turnstile solved on attempt %d", attempt + 1)
                    return token
                logger.warning("Turnstile returned no code on attempt %d", attempt + 1)
            except Exception as exc:
                logger.warning(
                    "Turnstile solve failed on attempt %d: %s",
                    attempt + 1, exc,
                )
            if attempt < self.max_retries - 1:
                time.sleep(2 * (attempt + 1))
        return None

    def find_sitekey_and_solve(self, page, captcha_type: str = "recaptcha") -> str | None:
        """Auto-detect sitekey from the page and solve the CAPTCHA.

        Looks for common CAPTCHA elements:
        - reCAPTCHA: g-recaptcha, recaptcha-sitekey, data-sitekey attributes
        - hCaptcha: hcaptcha-sitekey, data-sitekey on .h-captcha iframe
        - Turnstile: data-sitekey on .cf-turnstile

        Returns the token or None if no sitekey found or solving failed.
        """
        try:
            sitekey = page.evaluate("""() => {
                // Try common selectors for reCAPTCHA
                let el = document.querySelector('.g-recaptcha');
                if (el) return el.getAttribute('data-sitekey');
                el = document.querySelector('[data-sitekey]');
                if (el) return el.getAttribute('data-sitekey');
                // Try reCAPTCHA v3 (no visible element)
                if (window grecaptcha) {
                    return 'v3';
                }
                // Try hCaptcha
                el = document.querySelector('.h-captcha');
                if (el) return el.getAttribute('data-sitekey');
                // Try Cloudflare Turnstile
                el = document.querySelector('.cf-turnstile');
                if (el) return el.getAttribute('data-sitekey');
                return null;
            }""")
        except Exception as exc:
            logger.warning("Failed to extract sitekey: %s", exc)
            return None

        if not sitekey:
            logger.debug("No CAPTCHA sitekey found on page: %s", page.url)
            return None

        page_url = page.url
        if captcha_type in ("recaptcha", "recaptcha_v2"):
            return self.solve_recaptcha(page, sitekey, page_url)
        elif captcha_type == "recaptcha_v3":
            return self.solve_recaptcha(page, sitekey, page_url, version=3)
        elif captcha_type == "hcaptcha":
            return self.solve_hcaptcha(page, sitekey, page_url)
        elif captcha_type == "turnstile":
            return self.solve_turnstile(page, sitekey, page_url)
        else:
            # Auto-detect type by checking for known elements
            type_detection = page.evaluate("""() => {
                if (document.querySelector('.g-recaptcha') || window grecaptcha) return 'recaptcha';
                if (document.querySelector('.h-captcha')) return 'hcaptcha';
                if (document.querySelector('.cf-turnstile')) return 'turnstile';
                return null;
            }""")
            if type_detection == "recaptcha":
                return self.solve_recaptcha(page, sitekey, page_url)
            elif type_detection == "hcaptcha":
                return self.solve_hcaptcha(page, sitekey, page_url)
            elif type_detection == "turnstile":
                return self.solve_turnstile(page, sitekey, page_url)
            return None

    def apply_token(self, page, token: str, captcha_type: str = "recaptcha") -> bool:
        """Inject the solved token into the page's CAPTCHA field.

        Returns True if token was successfully injected.
        """
        if not token:
            return False
        try:
            if captcha_type in ("recaptcha", "recaptcha_v2"):
                # Submit the reCAPTCHA widget
                page.evaluate("""(token) => {
                    const form = document.querySelector('.g-recaptcha').closest('form');
                    if (form) {
                        // Trigger g-recaptcha callback
                        if (typeof grecaptcha !== 'undefined') {
                            grecaptcha.ready(function() {
                                grecaptcha.execute('').then(function(id) {
                                    // Manual token injection
                                    document.querySelectorAll('.g-recaptcha').forEach(el => {
                                        el.setAttribute('data-recaptcha-response', token);
                                    });
                                });
                            });
                        }
                    }
                    // Fallback: direct textarea injection
                    const textarea = document.querySelector('#g-recaptcha-response textarea');
                    if (textarea) textarea.value = token;
                }""", token)
            elif captcha_type == "turnstile":
                page.evaluate("""(token) => {
                    // Cloudflare Turnstile token injection
                    const widgets = document.querySelectorAll('.cf-turnstile');
                    widgets.forEach(el => {
                        const responseEl = el.querySelector('[name="cf-turnstile-response"]');
                        if (responseEl) responseEl.value = token;
                    });
                }""", token)
            elif captcha_type == "hcaptcha":
                page.evaluate("""(token) => {
                    const textarea = document.querySelector('textarea[name="h-captcha-response"]');
                    if (textarea) textarea.value = token;
                }""", token)
            logger.info("CAPTCHA token applied successfully (%s)", captcha_type)
            return True
        except Exception as exc:
            logger.warning("Failed to apply CAPTCHA token: %s", exc)
            return False
