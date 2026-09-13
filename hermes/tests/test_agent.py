"""Tests for the LLM-driven discovery agent."""

from __future__ import annotations

import json
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import MagicMock, Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1] / "src"))

from browser_guard import BrowserGuard, BrowserGuardError
from browser_tools import BrowserToolResult, BrowserToolSet, ToolNotAllowedError
from llm_client import LLMClient, LLMClientError, ToolCall

# Mock playwright before importing agent
import sys
from unittest.mock import MagicMock
sys.modules['playwright'] = MagicMock()
sys.modules['playwright.sync_api'] = MagicMock()

import config
from agent import Agent, _clear_stale_browser_profile_locks


class TestBrowserToolSet(unittest.TestCase):
    def setUp(self) -> None:
        self.page = Mock()
        self.guard = BrowserGuard(["www.amazon.com.br"])
        self.tools = BrowserToolSet(self.page, self.guard)

    def test_validate_allowed_tool(self) -> None:
        self.tools.validate_tool_name("navigate")
        self.tools.validate_tool_name("add_product_candidate")
        self.tools.validate_tool_name("finish")

    def test_validate_disallowed_tool(self) -> None:
        with self.assertRaises(ToolNotAllowedError):
            self.tools.validate_tool_name("execute_javascript")

    def test_finish_returns_candidates_count(self) -> None:
        result = self.tools.execute("finish", {})
        self.assertTrue(result.success)
        self.assertIn("0", result.message)

    def test_mercado_livre_profile_uses_native_headed_chrome(self) -> None:
        playwright = Mock()
        context = Mock()
        context.browser = Mock()
        playwright.chromium.launch_persistent_context.return_value = context
        agent = Agent(
            "Mercado Livre",
            "electronics",
            allowed_hosts=["www.mercadolivre.com.br"],
            browser_options={
                "headless": False,
                "native_user_agent": True,
                "stealth_script": False,
                "llm_page_segment_chars": 4000,
                "llm_max_links_per_segment": 30,
                "listing_image_enrichment": True,
                "require_image": True,
            },
        )

        agent._launch_browser(playwright)

        options = playwright.chromium.launch_persistent_context.call_args.kwargs
        self.assertFalse(options["headless"])
        self.assertNotIn("user_agent", options)
        context.add_init_script.assert_not_called()
        self.assertTrue(agent.require_image)
        self.assertEqual(agent.llm_page_segment_chars, 4000)
        self.assertEqual(agent.llm_max_links_per_segment, 30)
        self.assertTrue(agent.listing_image_enrichment)

    def test_default_profile_preserves_existing_browser_behavior(self) -> None:
        playwright = Mock()
        context = Mock()
        context.browser = Mock()
        playwright.chromium.launch_persistent_context.return_value = context
        agent = Agent("Amazon", "electronics", allowed_hosts=["www.amazon.com.br"])

        agent._launch_browser(playwright)

        options = playwright.chromium.launch_persistent_context.call_args.kwargs
        self.assertTrue(options["headless"])
        self.assertIn("Chrome/126.0.0.0", options["user_agent"])
        context.add_init_script.assert_called_once()
        self.assertFalse(agent.require_image)
        self.assertFalse(agent.listing_image_enrichment)
        self.assertEqual(agent.llm_page_segment_chars, config.LLM_PAGE_SEGMENT_CHARS)
        self.assertIsNone(agent.llm_max_links_per_segment)

    def test_stale_persistent_profile_locks_are_removed(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            profile = Path(directory)
            (profile / "SingletonSocket").symlink_to("/tmp/missing-chrome-socket")
            (profile / "SingletonLock").symlink_to("old-container-123")
            (profile / "SingletonCookie").symlink_to("123456")

            _clear_stale_browser_profile_locks(directory)

            self.assertFalse((profile / "SingletonSocket").is_symlink())
            self.assertFalse((profile / "SingletonLock").is_symlink())
            self.assertFalse((profile / "SingletonCookie").is_symlink())

    def test_active_persistent_profile_locks_are_preserved(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            profile = Path(directory)
            socket_target = profile / "active-socket"
            socket_target.touch()
            (profile / "SingletonSocket").symlink_to(socket_target)
            (profile / "SingletonLock").symlink_to("current-container-123")

            _clear_stale_browser_profile_locks(directory)

            self.assertTrue((profile / "SingletonSocket").is_symlink())
            self.assertTrue((profile / "SingletonLock").is_symlink())

    def test_finish_exhausted_requires_all_configured_urls(self) -> None:
        tools = BrowserToolSet(
            self.page,
            self.guard,
            target_candidates=2,
            required_urls=[
                "https://www.amazon.com.br/deals",
                "https://www.amazon.com.br/bestsellers",
            ],
        )
        tools.visited_urls.add("https://www.amazon.com.br/deals")

        result = tools.execute("finish", {"exhausted": True, "reason": "No more pages"})

        self.assertFalse(result.success)
        self.assertIn("bestsellers", result.message)

        tools.visited_urls.add("https://www.amazon.com.br/bestsellers")
        result = tools.execute("finish", {"exhausted": True, "reason": "No more pages"})

        self.assertTrue(result.success)
        self.assertTrue(result.data["exhausted"])

    def test_add_product_candidate_success(self) -> None:
        result = self.tools.execute("add_product_candidate", {
            "url": "https://www.amazon.com.br/dp/B123",
            "title": "Kindle Paperwhite 32GB",
            "price": "R$ 499,00",
            "original_price": "R$ 699,00",
            "image_url": "https://m.media-amazon.com/images/example.jpg",
        })

        self.assertTrue(result.success)
        self.assertEqual(len(self.tools.candidates), 1)
        self.assertEqual(self.tools.candidates[0].title, "Kindle Paperwhite 32GB")

    def test_add_product_candidate_blocks_non_allowlisted_url(self) -> None:
        result = self.tools.execute("add_product_candidate", {
            "url": "https://evil.com/product",
            "title": "Some Product",
            "price": "R$ 100,00",
        })

        self.assertFalse(result.success)
        self.assertEqual(len(self.tools.candidates), 0)




class TestLLMClient(unittest.TestCase):
    def test_missing_base_url_raises(self) -> None:
        client = LLMClient(base_url="", api_key="secret", model="test")
        with self.assertRaises(LLMClientError):
            client.chat_completion([], [])

    @patch("llm_client.requests.post")
    def test_chat_completion_returns_tool_call(self, mock_post: Mock) -> None:
        mock_post.return_value.json.return_value = {
            "choices": [
                {
                    "message": {
                        "tool_calls": [
                            {
                                "function": {
                                    "name": "navigate",
                                    "arguments": '{"url": "https://www.amazon.com.br/"}',
                                }
                            }
                        ]
                    }
                }
            ]
        }
        mock_post.return_value.raise_for_status = Mock()

        client = LLMClient(
            base_url="https://api.example.com",
            api_key="secret",
            model="test",
        )
        result = client.chat_completion([], [])

        self.assertEqual(result.name, "navigate")
        self.assertEqual(result.arguments["url"], "https://www.amazon.com.br/")
        self.assertEqual(mock_post.call_args.kwargs["timeout"], (5, 120))


class TestBrowserGuard(unittest.TestCase):
    def test_blocks_non_allowlisted_host(self) -> None:
        guard = BrowserGuard(["www.amazon.com.br"])
        with self.assertRaises(BrowserGuardError):
            guard.validate_url("https://evil.com/")

    def test_allows_amazon_brazil(self) -> None:
        guard = BrowserGuard(["www.amazon.com.br"])
        validated = guard.validate_url("https://www.amazon.com.br/gp/goldbox")
        self.assertEqual(validated, "https://www.amazon.com.br/gp/goldbox")






class TestSecretRedaction(unittest.TestCase):
    def test_api_key_redacted_in_logs(self) -> None:
        """Verify that API key is redacted in logs."""
        import config
        
        # Save original value
        original_key = config.LLM_API_KEY
        
        try:
            # Set a test API key
            config.LLM_API_KEY = "test-secret-key-12345"
            
            # Test redaction
            text_with_secret = f"Authorization: Bearer {config.LLM_API_KEY}"
            redacted = config.redact_secrets(text_with_secret)
            
            self.assertNotIn(config.LLM_API_KEY, redacted)
            self.assertIn("***REDACTED***", redacted)
        finally:
            # Restore original value
            config.LLM_API_KEY = original_key

    def test_tool_arguments_redacted(self) -> None:
        """Verify that tool arguments are redacted in step logs."""
        import config
        
        original_key = config.LLM_API_KEY
        config.LLM_API_KEY = "test-secret-key-12345"

        try:
            agent = Agent(
                marketplace="amazon",
                goal="find electronics",
            )
            
            args = {"url": f"https://api.example.com?key={config.LLM_API_KEY}"}
            redacted = agent._redact_arguments(args)
            
            self.assertNotIn(config.LLM_API_KEY, redacted["url"])
            self.assertIn("***REDACTED***", redacted["url"])
        finally:
            config.LLM_API_KEY = original_key


class TestForbiddenTools(unittest.TestCase):
    def test_no_shell_tool(self) -> None:
        """Verify that shell execution tool is not available."""
        forbidden_tools = [
            "shell",
            "execute_shell",
            "run_command",
            "bash",
        ]
        
        for tool_name in forbidden_tools:
            with self.assertRaises(ToolNotAllowedError):
                page = Mock()
                guard = BrowserGuard(["www.amazon.com.br"])
                tools = BrowserToolSet(page, guard)
                tools.validate_tool_name(tool_name)

    def test_no_filesystem_tool(self) -> None:
        """Verify that filesystem access tool is not available."""
        forbidden_tools = [
            "read_file",
            "write_file",
            "list_files",
            "filesystem",
        ]
        
        for tool_name in forbidden_tools:
            with self.assertRaises(ToolNotAllowedError):
                page = Mock()
                guard = BrowserGuard(["www.amazon.com.br"])
                tools = BrowserToolSet(page, guard)
                tools.validate_tool_name(tool_name)

    def test_no_raw_http_tool(self) -> None:
        """Verify that raw HTTP request tool is not available."""
        forbidden_tools = [
            "http_request",
            "fetch",
            "request",
            "arbitrary_http_request",
        ]
        
        for tool_name in forbidden_tools:
            with self.assertRaises(ToolNotAllowedError):
                page = Mock()
                guard = BrowserGuard(["www.amazon.com.br"])
                tools = BrowserToolSet(page, guard)
                tools.validate_tool_name(tool_name)

    def test_no_raw_playwright_tool(self) -> None:
        """Verify that raw Playwright access tool is not available."""
        forbidden_tools = [
            "execute_javascript",
            "evaluate",
            "raw_playwright",
            "playwright",
        ]
        
        for tool_name in forbidden_tools:
            with self.assertRaises(ToolNotAllowedError):
                page = Mock()
                guard = BrowserGuard(["www.amazon.com.br"])
                tools = BrowserToolSet(page, guard)
                tools.validate_tool_name(tool_name)




class TestParsePrice(unittest.TestCase):
    def test_brazilian_format(self) -> None:
        from agent import _parse_price

        self.assertEqual(_parse_price("R$ 2,00"), 2.0)
        self.assertEqual(_parse_price("R$ 1.234,56"), 1234.56)
        self.assertEqual(_parse_price("R$ 7.999,00"), 7999.0)

    def test_english_format(self) -> None:
        from agent import _parse_price

        self.assertEqual(_parse_price("$199.99"), 199.99)
        self.assertEqual(_parse_price("199.99"), 199.99)
        self.assertEqual(_parse_price("1,234.56"), 1234.56)

    def test_integer_with_thousands_separator(self) -> None:
        from agent import _parse_price

        self.assertEqual(_parse_price("R$ 1.234"), 1234.0)
        self.assertEqual(_parse_price("1,234"), 1234.0)

    def test_invalid_values(self) -> None:
        from agent import _parse_price

        self.assertIsNone(_parse_price(None))
        self.assertIsNone(_parse_price(""))
        self.assertIsNone(_parse_price("grátis"))

    def test_rejects_installment_text(self) -> None:
        from agent import _parse_price

        self.assertIsNone(_parse_price("10x de R$ 200,00"))
        self.assertIsNone(_parse_price("12x de $ 150,00"))
        self.assertIsNone(_parse_price("6x de R$199,90"))


class TestCandidateMeetsMinimumDiscount(unittest.TestCase):
    def test_accepts_valid_discount(self) -> None:
        from agent import _candidate_meets_minimum_discount
        from browser_tools import ProductCandidate

        candidate = ProductCandidate(
            url="https://example.com/p",
            title="Product",
            price="R$ 800,00",
            original_price="R$ 1.000,00",
        )
        valid, reason = _candidate_meets_minimum_discount(candidate, 10)
        self.assertTrue(valid)
        self.assertIn("discount 20.0%", reason)

    def test_rejects_suspiciously_low_price(self) -> None:
        from agent import _candidate_meets_minimum_discount
        from browser_tools import ProductCandidate

        candidate = ProductCandidate(
            url="https://example.com/p",
            title="iPhone",
            price="R$ 2,00",
            original_price="R$ 7.999,00",
        )
        valid, reason = _candidate_meets_minimum_discount(candidate, 10)
        self.assertFalse(valid)
        self.assertIn("less than 10%", reason)

    def test_rejects_missing_original_price_when_discount_is_required(self) -> None:
        from agent import _candidate_meets_minimum_discount
        from browser_tools import ProductCandidate

        candidate = ProductCandidate(
            url="https://example.com/p",
            title="Product",
            price="R$ 600,00",
        )
        valid, reason = _candidate_meets_minimum_discount(candidate, 10)
        self.assertFalse(valid)
        self.assertIn("original price could not be confirmed", reason)


class TestEnrichCandidates(unittest.TestCase):
    def test_selects_accessible_product_image(self) -> None:
        images = [
            {"src": "https://example.com/logo.png", "alt": "Logo", "width": 800, "height": 400, "visible": True},
            {"src": "https://example.com/product.jpg", "alt": "Product image", "width": 600, "height": 600, "visible": True},
            {"src": "https://example.com/icon.svg", "alt": "Icon", "width": 1000, "height": 1000, "visible": True},
        ]

        self.assertEqual(
            BrowserToolSet._select_accessible_image(images),
            "https://example.com/product.jpg",
        )

    def test_selects_first_product_image_and_ignores_review_image(self) -> None:
        images = [
            {"src": "https://example.com/main.jpg", "alt": "Product image", "width": 500, "height": 500,
             "visible": True, "order": 0},
            {"src": "https://example.com/review.jpg", "alt": "Customer review photo", "width": 1200,
             "height": 1200, "visible": True, "order": 1},
        ]

        self.assertEqual(
            BrowserToolSet._select_accessible_image(images),
            "https://example.com/main.jpg",
        )

    def test_relaxed_image_selection_keeps_semantic_filters(self) -> None:
        images = [
            {"src": "https://example.com/logo.png", "alt": "Logo", "width": 100, "height": 100,
             "visible": True, "order": 0},
            {"src": "https://example.com/product-small.jpg", "alt": "Produto", "width": 120, "height": 120,
             "visible": True, "order": 1},
        ]

        self.assertEqual(
            BrowserToolSet._select_accessible_image(images, minimum_area=10_000),
            "https://example.com/product-small.jpg",
        )

    def test_ignores_amazon_promotional_banner(self) -> None:
        images = [
            {"src": "https://m.media-amazon.com/images/G/32/digital/video/merch/banner.jpg",
             "alt": "Atraídos pelo destino", "width": 800, "height": 78, "visible": True, "order": 0},
            {"src": "https://m.media-amazon.com/images/I/product.jpg", "alt": "Smart TV LG",
             "width": 500, "height": 500, "visible": True, "order": 1},
        ]

        self.assertEqual(
            BrowserToolSet._select_accessible_image(images),
            "https://m.media-amazon.com/images/I/product.jpg",
        )

        self.assertFalse(BrowserToolSet._is_likely_product_image(
            "https://m.media-amazon.com/images/G/32/digital/video/merch/banner.jpg"
        ))

    def test_open_graph_image_has_priority_over_inconsistent_product_json_ld(self) -> None:
        tools = BrowserToolSet(Mock(), BrowserGuard(["example.com"]))
        metadata = tools._parse_product_metadata({
            "og": {"og:image": "https://example.com/page-preview.jpg"},
            "twitter": {},
            "image_src": None,
            "json_ld": [{
                "@type": "Product",
                "name": "Example product",
                "image": ["https://example.com/main.jpg", "https://example.com/second.jpg"],
                "offers": {"price": "99.90"},
            }],
        })

        self.assertEqual(metadata["image"], "https://example.com/page-preview.jpg")

    def test_protocol_relative_image_is_normalized(self) -> None:
        tools = BrowserToolSet(Mock(), BrowserGuard(["example.com"]))
        metadata = tools._parse_product_metadata({
            "url": "https://example.com/product",
            "og": {"og:image": "//cdn.example.com/product.jpg"},
            "twitter": {},
            "json_ld": [],
        })

        self.assertEqual(metadata["image"], "https://cdn.example.com/product.jpg")

    def test_listing_images_are_compacted_for_llm(self) -> None:
        agent = Agent("example", "electronics", allowed_hosts=["example.com"])
        images = agent._compact_observed_images([
            {"src": "https://example.com/product.jpg", "alt": " Product A ", "order": 1},
            {"src": "https://example.com/product.jpg", "alt": "duplicate"},
            {"src": "data:image/gif;base64,placeholder", "alt": "placeholder"},
        ])

        self.assertEqual(images, [{"src": "https://example.com/product.jpg", "alt": "Product A", "order": 1}])

    def test_visible_text_fallback_confirms_prices(self) -> None:
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        resolver = Mock(return_value={"price": "600.00", "original_price": "1000.00"})
        tools = BrowserToolSet(page, guard, metadata_resolver=resolver)

        tools.execute("add_product_candidate", {
            "url": "https://www.amazon.com.br/dp/B789",
            "title": "Product C",
            "price": "R$ 60,00",
            "original_price": "R$ 100,00",
        })

        with patch.object(
            tools,
            "_tool_get_product_metadata",
            return_value=BrowserToolResult(
                success=True,
                message="ok",
                data={"title": "Product C", "visible_text": "Por R$ 600,00. De R$ 1.000,00."},
            ),
        ):
            tools.enrich_candidates()

        resolver.assert_called_once()
        self.assertEqual(tools.candidates[0].price, "R$ 60,00")
        self.assertEqual(tools.candidates[0].original_price, "1000.00")

    def test_visible_text_fallback_does_not_override_structured_price(self) -> None:
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        resolver = Mock(return_value={"price": "123.00", "original_price": "200.00"})
        tools = BrowserToolSet(page, guard, metadata_resolver=resolver)

        tools.execute("add_product_candidate", {
            "url": "https://www.amazon.com.br/dp/B999",
            "title": "Product with structured price",
            "price": "R$ 600,00",
            "original_price": "R$ 1000,00",
        })

        with patch.object(
            tools,
            "_tool_get_product_metadata",
            return_value=BrowserToolResult(
                success=True,
                message="ok",
                data={
                    "title": "Product with structured price",
                    "price": "600.00",
                    "visible_text": "Por R$ 123,00. De R$ 200,00.",
                },
            ),
        ):
            tools.enrich_candidates()

        self.assertEqual(tools.candidates[0].price, "600.00")
        self.assertEqual(tools.candidates[0].original_price, "200.00")

    def test_price_overridden_by_metadata(self) -> None:
        """Metadata price from the product page must override the listing price."""
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        tools = BrowserToolSet(page, guard)

        tools.execute("add_product_candidate", {
            "url": "https://www.amazon.com.br/dp/B123",
            "title": "Product A",
            "price": "R$ 2,00",
            "original_price": "R$ 7.999,00",
        })

        # Simulate metadata returning the correct product-page price
        with patch.object(
            tools,
            "_tool_get_product_metadata",
            return_value=BrowserToolResult(
                success=True,
                message="ok",
                data={
                    "title": "Product A",
                    "price": "7999.00",
                    "original_price": "9999.00",
                    "image": "https://example.com/image.jpg",
                },
            ),
        ):
            tools.enrich_candidates()

        self.assertEqual(len(tools.candidates), 1)
        self.assertEqual(tools.candidates[0].price, "7999.00")
        self.assertEqual(tools.candidates[0].original_price, "9999.00")

    def test_offer_specific_url_preserves_listing_prices_but_uses_metadata_image(self) -> None:
        """Catalog metadata must not replace the price of a selected offer."""
        page = Mock()
        guard = BrowserGuard(["www.mercadolivre.com.br"])
        tools = BrowserToolSet(page, guard)

        tools.execute("add_product_candidate", {
            "url": (
                "https://www.mercadolivre.com.br/produto/p/MLB123"
                "?pdp_filters=deal%3AX&wid=MLB456&deal_print_id=abc"
            ),
            "title": "Smart TV",
            "price": "R$ 2.599,00",
            "original_price": "R$ 3.299,00",
        })

        with patch.object(
            tools,
            "_tool_get_product_metadata",
            return_value=BrowserToolResult(
                success=True,
                message="ok",
                data={
                    "title": "Smart TV",
                    "price": "2325.00",
                    "original_price": "3299.00",
                    "image": "https://http2.mlstatic.com/tv.jpg",
                },
            ),
        ):
            tools.enrich_candidates()

        self.assertEqual(tools.candidates[0].price, "R$ 2.599,00")
        self.assertEqual(tools.candidates[0].original_price, "R$ 3.299,00")
        self.assertEqual(tools.candidates[0].image_url, "https://http2.mlstatic.com/tv.jpg")

    def test_original_price_enriched_when_missing(self) -> None:
        """Original price must be filled from metadata when not provided by the LLM."""
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        tools = BrowserToolSet(page, guard)

        tools.execute("add_product_candidate", {
            "url": "https://www.amazon.com.br/dp/B456",
            "title": "Product B",
            "price": "R$ 499,00",
        })

        with patch.object(
            tools,
            "_tool_get_product_metadata",
            return_value=BrowserToolResult(
                success=True,
                message="ok",
                data={
                    "title": "Product B",
                    "price": "499.00",
                    "original_price": "699.00",
                },
            ),
        ):
            tools.enrich_candidates()

        self.assertEqual(tools.candidates[0].original_price, "699.00")


class TestBlockedURL(unittest.TestCase):
    def test_blocked_url_fails(self) -> None:
        """Verify that a URL blocked by Browser Guard fails."""
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        tools = BrowserToolSet(page, guard)

        # Try to navigate to a non-allowlisted host
        result = tools.execute("navigate", {"url": "https://evil.com/"})

        self.assertFalse(result.success)
        self.assertIn("Host is not allowlisted", result.message)

    def test_blocked_marketplace_path_fails(self) -> None:
        """Verify that sensitive marketplace paths are blocked."""
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        tools = BrowserToolSet(page, guard)

        # Try to navigate to checkout
        result = tools.execute("navigate", {"url": "https://www.amazon.com.br/cart"})

        self.assertFalse(result.success)
        self.assertIn("blocked", result.message.lower())


class TestInvalidCandidate(unittest.TestCase):
    def test_candidate_with_blocked_url_discarded(self) -> None:
        """Verify that candidate with non-allowlisted URL is discarded."""
        page = Mock()
        guard = BrowserGuard(["www.amazon.com.br"])
        tools = BrowserToolSet(page, guard)

        result = tools.execute("add_product_candidate", {
            "url": "https://evil.com/product",
            "title": "Some Product",
            "price": "R$ 100,00",
        })

        self.assertFalse(result.success)
        self.assertEqual(len(tools.candidates), 0)
