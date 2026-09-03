"""Tests for the LLM-driven discovery agent."""

from __future__ import annotations

import json
import sys
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

from agent import Agent


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


class TestAgentLimits(unittest.TestCase):
    def test_max_steps_enforced(self) -> None:
        llm_client = Mock(spec=LLMClient)
        llm_client.chat_completion.side_effect = [
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="inspect_page", arguments={}),
        ]

        agent = Agent(
            marketplace="amazon",
            goal="find electronics",
            llm_client=llm_client,
            max_steps=2,
            max_pages=10,
            max_raw_candidates=10,
            max_selected_candidates=10,
            run_timeout_seconds=60,
        )

        mock_browser = Mock()
        mock_context = Mock()
        mock_page = Mock()

        with patch.object(agent, "_launch_browser", return_value=(mock_browser, mock_context, mock_page)):
            with patch.object(BrowserToolSet, "execute") as mock_execute:
                mock_execute.return_value = Mock(
                    success=True,
                    message="ok",
                    data={"url": "https://www.amazon.com.br/", "title": "Amazon"},
                )
                with patch("agent.setup_signal_handlers"):
                    report = agent.run()

        self.assertEqual(report["status"], "completed")
        # max_steps=2 means at most 2 iterations
        self.assertLessEqual(len(report["steps"]), 2)


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


class TestBrowserGuard(unittest.TestCase):
    def test_blocks_non_allowlisted_host(self) -> None:
        guard = BrowserGuard(["www.amazon.com.br"])
        with self.assertRaises(BrowserGuardError):
            guard.validate_url("https://evil.com/")

    def test_allows_amazon_brazil(self) -> None:
        guard = BrowserGuard(["www.amazon.com.br"])
        validated = guard.validate_url("https://www.amazon.com.br/gp/goldbox")
        self.assertEqual(validated, "https://www.amazon.com.br/gp/goldbox")


class TestPageLimits(unittest.TestCase):
    def test_max_pages_enforced(self) -> None:
        """Verify that page navigation limit is respected."""
        llm_client = Mock(spec=LLMClient)
        llm_client.chat_completion.side_effect = [
            ToolCall(name="navigate", arguments={"url": "https://www.amazon.com.br/"}),
            ToolCall(name="navigate", arguments={"url": "https://www.amazon.com.br/gp/goldbox"}),
            ToolCall(name="navigate", arguments={"url": "https://www.amazon.com.br/deals"}),
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="finish", arguments={}),
        ]

        agent = Agent(
            marketplace="amazon",
            goal="find electronics",
            llm_client=llm_client,
            max_steps=10,
            max_pages=2,
            max_raw_candidates=10,
            max_selected_candidates=10,
            run_timeout_seconds=60,
        )

        mock_browser = Mock()
        mock_context = Mock()
        mock_page = Mock()

        with patch.object(agent, "_launch_browser", return_value=(mock_browser, mock_context, mock_page)):
            # Mock _tool_navigate to increment page_count (as the real implementation does)
            def mock_navigate(self_bt, url):
                self_bt.page_count += 1
                return BrowserToolSet._tool_inspect_page(self_bt)

            with patch.object(BrowserToolSet, "_tool_navigate", mock_navigate):
                with patch("agent.setup_signal_handlers"):
                    report = agent.run()

        self.assertEqual(report["status"], "completed")
        # Should have stopped before executing all 5 tool calls
        self.assertLess(len(report["steps"]), 5)


class TestTimeout(unittest.TestCase):
    def test_timeout_enforced(self) -> None:
        """Verify that timeout stops execution."""
        llm_client = Mock(spec=LLMClient)
        llm_client.chat_completion.side_effect = [
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="finish", arguments={}),
        ]

        agent = Agent(
            marketplace="amazon",
            goal="find electronics",
            llm_client=llm_client,
            max_steps=10,
            max_pages=10,
            max_raw_candidates=10,
            max_selected_candidates=10,
            run_timeout_seconds=60,
        )

        mock_browser = Mock()
        mock_context = Mock()
        mock_page = Mock()

        # Simulate time passing beyond the timeout after first step
        fake_times = [0.0, 5.0, 70.0, 80.0]

        with patch("agent.time") as mock_time:
            mock_time.time = Mock(side_effect=fake_times)
            with patch.object(agent, "_launch_browser", return_value=(mock_browser, mock_context, mock_page)):
                with patch("agent.setup_signal_handlers"):
                    report = agent.run()

        self.assertEqual(report["status"], "completed")
        # Should have stopped after first tool call due to timeout
        self.assertLess(len(report["steps"]), 3)


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


class TestMockLLMFlow(unittest.TestCase):
    def test_mock_llm_controlled_flow(self) -> None:
        """Verify that a mock LLM can navigate through a controlled flow."""
        llm_client = Mock(spec=LLMClient)
        llm_client.chat_completion.side_effect = [
            ToolCall(name="navigate", arguments={"url": "https://www.amazon.com.br/gp/goldbox"}),
            ToolCall(name="inspect_page", arguments={}),
            ToolCall(name="add_product_candidate", arguments={
                "url": "https://www.amazon.com.br/dp/B123",
                "title": "Kindle Paperwhite 32GB",
                "price": "R$ 499,00",
            }),
            ToolCall(name="finish", arguments={}),
        ]

        agent = Agent(
            marketplace="amazon",
            goal="find electronics",
            llm_client=llm_client,
            max_steps=10,
            max_pages=10,
            max_raw_candidates=10,
            max_selected_candidates=10,
            run_timeout_seconds=60,
        )

        mock_browser = Mock()
        mock_context = Mock()
        mock_page = Mock()

        with patch.object(agent, "_launch_browser", return_value=(mock_browser, mock_context, mock_page)):
            with patch.object(BrowserToolSet, "execute") as mock_execute:
                mock_execute.return_value = Mock(
                    success=True,
                    message="ok",
                    data={"url": "https://www.amazon.com.br/", "title": "Amazon"},
                )
                with patch("agent.setup_signal_handlers"):
                    report = agent.run()

        self.assertEqual(report["status"], "completed")
        self.assertEqual(len(report["steps"]), 4)
        self.assertEqual(llm_client.chat_completion.call_count, 4)


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


class TestEnrichCandidates(unittest.TestCase):
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
        self.assertEqual(tools.candidates[0].original_price, "R$ 7.999,00")

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
