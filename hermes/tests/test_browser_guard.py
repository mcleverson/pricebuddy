import sys
import unittest
from pathlib import Path
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).parents[1] / "src"))

from browser_guard import BrowserGuard, BrowserGuardError  # noqa: E402


class BrowserGuardTest(unittest.TestCase):
    def setUp(self) -> None:
        self.guard = BrowserGuard(["shop.example", "example.com"])

    def assertBlocked(self, url: str) -> None:
        with self.assertRaises(BrowserGuardError):
            self.guard.validate_url(url)

    def test_allowlisted_https_product_is_allowed(self) -> None:
        self.assertEqual(
            "https://shop.example/product/123",
            self.guard.validate_url("https://shop.example/product/123"),
        )

    def test_rejects_non_allowlisted_host(self) -> None:
        self.assertBlocked("https://other.example/product")

    def test_allows_get_and_head_for_an_allowlisted_navigation(self) -> None:
        for method in ("GET", "HEAD"):
            self.guard.validate_request(
                "https://shop.example/product/123",
                method,
                navigation=True,
            )

    def test_blocks_write_methods_in_read_only_mode(self) -> None:
        for method in ("POST", "PUT", "PATCH", "DELETE", "OPTIONS"):
            with self.assertRaises(BrowserGuardError):
                self.guard.validate_request(
                    "https://shop.example/product/123",
                    method,
                    navigation=True,
                )

    def test_rejects_non_https_and_dangerous_schemes(self) -> None:
        for scheme in ("http", "file", "data", "javascript", "chrome", "about", "ftp"):
            self.assertBlocked(f"{scheme}://shop.example/product")

    def test_rejects_local_and_private_destinations(self) -> None:
        for url in (
            "https://localhost/product",
            "https://127.0.0.1/product",
            "https://10.0.0.1/product",
            "https://192.168.1.10/product",
            "https://169.254.169.254/latest",
            "https://[::1]/product",
        ):
            self.assertBlocked(url)

    def test_allows_product_path_and_blocks_sensitive_paths_case_insensitively(self) -> None:
        self.guard.validate_url("https://shop.example/product/123")

        for path in ("cart", "checkout", "orders", "order", "buy", "payment", "payments", "wallet", "address", "addresses", "account", "settings"):
            self.assertBlocked(f"https://shop.example/{path.upper()}")

    def test_intercepts_blocked_requests(self) -> None:
        route = Mock()
        route.request.url = "https://shop.example/checkout"
        route.request.method = "GET"
        route.request.resource_type = "document"

        self.guard._handle_route(route)

        route.abort.assert_called_once_with("blockedbyclient")
        route.continue_.assert_not_called()

    def test_allows_intercepted_requests_on_allowlisted_product(self) -> None:
        route = Mock()
        route.request.url = "https://shop.example/product/123"
        route.request.method = "GET"
        route.request.resource_type = "document"

        self.guard._handle_route(route)

        route.continue_.assert_called_once_with()
        route.abort.assert_not_called()

    def test_redirect_target_is_validated_by_request_interception(self) -> None:
        route = Mock()
        route.request.url = "https://other.example/product/123"
        route.request.method = "GET"
        route.request.resource_type = "document"

        self.guard._handle_route(route)

        route.abort.assert_called_once_with("blockedbyclient")

    def test_allows_public_external_subresource_without_navigation_allowlist(self) -> None:
        route = Mock()
        route.request.url = "https://cdn.example.net/assets/app.js"
        route.request.method = "GET"
        route.request.resource_type = "script"

        self.guard._handle_route(route)

        route.continue_.assert_called_once_with()
        route.abort.assert_not_called()

        http_route = Mock()
        http_route.request.url = "http://cdn.example.net/assets/legacy.css"
        http_route.request.method = "GET"
        http_route.request.resource_type = "stylesheet"

        self.guard._handle_route(http_route)

        http_route.continue_.assert_called_once_with()
        http_route.abort.assert_not_called()

    def test_blocks_local_subresources(self) -> None:
        for url in (
            "https://localhost/health",
            "https://127.0.0.1/health",
            "https://10.0.0.1/health",
            "https://169.254.169.254/latest",
        ):
            route = Mock()
            route.request.url = url
            route.request.method = "GET"
            route.request.resource_type = "script"

            self.guard._handle_route(route)

            route.abort.assert_called_once_with("blockedbyclient")

    def test_blocks_write_subresource_requests(self) -> None:
        route = Mock()
        route.request.url = "https://cdn.example.net/api/telemetry"
        route.request.method = "POST"
        route.request.resource_type = "fetch"

        self.guard._handle_route(route)

        route.abort.assert_called_once_with("blockedbyclient")

    def test_similar_or_malicious_host_does_not_match_allowlist(self) -> None:
        self.assertBlocked("https://shop.example.evil.test/product/123")
        self.assertBlocked("https://notshop.example/product/123")

    def test_redirect_to_www_requires_www_to_be_allowlisted(self) -> None:
        guard = BrowserGuard(["amazon.com.br"])
        self.assertBlockedWithGuard(guard, "https://www.amazon.com.br/product/123")

        guard = BrowserGuard(["amazon.com.br", "www.amazon.com.br"])
        guard.validate_url("https://www.amazon.com.br/product/123")

    def assertBlockedWithGuard(self, guard: BrowserGuard, url: str) -> None:
        with self.assertRaises(BrowserGuardError):
            guard.validate_url(url)


if __name__ == "__main__":
    unittest.main()
