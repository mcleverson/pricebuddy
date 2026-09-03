"""Centralised, fail-closed navigation policy for Hermes."""

from __future__ import annotations

import ipaddress
import os
import re
from urllib.parse import unquote, urlsplit


class BrowserGuardError(ValueError):
    """Raised when a browser navigation or request violates the policy."""


class BrowserGuard:
    """Validate navigations and intercept all requests made by a page."""

    ALLOWED_NAVIGATION_SCHEMES = frozenset({"https"})
    ALLOWED_RESOURCE_SCHEMES = frozenset({"http", "https"})
    READ_METHODS = frozenset({"GET", "HEAD"})

    BLOCKED_PATH = re.compile(
        r"(?:^|/)(cart|checkout|buy|orders?|payments?|wallet|address(?:es)?|account|settings)(?:/|$)",
        re.IGNORECASE,
    )

    def __init__(self, allowed_hosts: str | list[str] | tuple[str, ...] | None = None) -> None:
        if allowed_hosts is None:
            allowed_hosts = os.environ.get("HERMES_ALLOWED_HOSTS", "")

        if isinstance(allowed_hosts, str):
            values = allowed_hosts.split(",")
        else:
            values = allowed_hosts

        self.allowed_hosts = frozenset(
            host.strip().lower().rstrip(".")
            for host in values
            if host.strip()
        )
        self.allowed_request_count = 0
        self.blocked_requests: list[dict[str, str]] = []
        self.installed = False

    def validate_url(self, url: str, *, navigation: bool = True) -> str:
        """Return a validated URL or raise according to navigation/resource policy."""
        if not isinstance(url, str) or not url.strip():
            raise BrowserGuardError("URL is required")

        raw_url = url.strip()
        parsed = urlsplit(raw_url)
        scheme = parsed.scheme.lower()

        allowed_schemes = (
            self.ALLOWED_NAVIGATION_SCHEMES
            if navigation
            else self.ALLOWED_RESOURCE_SCHEMES
        )
        if scheme not in allowed_schemes:
            allowed = ", ".join(sorted(allowed_schemes))
            raise BrowserGuardError(f"Only these URL schemes are allowed: {allowed}")

        if parsed.username or parsed.password:
            raise BrowserGuardError("Userinfo in URLs is not allowed")

        try:
            host = (parsed.hostname or "").lower().rstrip(".")
            port = parsed.port
        except ValueError as exc:
            raise BrowserGuardError("Invalid URL host or port") from exc

        standard_port = 443 if scheme == "https" else 80
        if not host or port not in (None, standard_port):
            raise BrowserGuardError("Only standard HTTP(S) ports are allowed")

        self._reject_sensitive_host(host)

        if navigation and host not in self.allowed_hosts:
            raise BrowserGuardError(f"Host is not allowlisted: {host}")

        path = unquote(unquote(parsed.path or "/"))
        if navigation and self.BLOCKED_PATH.search(path):
            raise BrowserGuardError(f"Sensitive marketplace path is blocked: {path}")

        return raw_url

    def validate_request(self, url: str, method: str, *, navigation: bool = False) -> str:
        """Validate a browser request, denying every non-read method."""
        normalized_method = method.upper()
        if normalized_method not in self.READ_METHODS:
            raise BrowserGuardError(f"HTTP method is blocked in read-only mode: {normalized_method}")

        return self.validate_url(url, navigation=navigation)

    def install(self, page) -> None:
        """Install request interception before the first navigation."""
        if not self.installed:
            page.route("**/*", self._handle_route)
            self.installed = True

    def safe_navigate(self, page, url: str, **kwargs):
        """Install the guard, validate the target, and navigate through one API."""
        validated_url = self.validate_url(url)
        self.install(page)
        return page.goto(validated_url, **kwargs)

    def _handle_route(self, route) -> None:
        request = route.request
        is_document = request.resource_type == "document"

        try:
            self.validate_request(request.url, request.method, navigation=is_document)
        except BrowserGuardError as exc:
            parsed = urlsplit(request.url)
            self.blocked_requests.append(
                {
                    "method": request.method.upper(),
                    "resource_type": request.resource_type,
                    "hostname": (parsed.hostname or "").lower(),
                    "path": parsed.path or "/",
                    "reason": str(exc),
                }
            )
            route.abort("blockedbyclient")
            return

        self.allowed_request_count += 1
        route.continue_()

    @staticmethod
    def _reject_sensitive_host(host: str) -> None:
        if host == "localhost" or host.endswith(".localhost"):
            raise BrowserGuardError("Localhost is blocked")

        try:
            address = ipaddress.ip_address(host)
        except ValueError:
            return

        if (
            address.is_loopback
            or address.is_private
            or address.is_link_local
            or address.is_unspecified
            or address.is_reserved
            or address.is_multicast
        ):
            raise BrowserGuardError(f"Sensitive IP address is blocked: {host}")
