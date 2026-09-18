"""Minimal OpenAI-compatible LLM client for Hermes agent."""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass
from typing import Any

import requests

import config


logger = logging.getLogger(__name__)


@dataclass
class ToolCall:
    name: str
    arguments: dict[str, Any]


class LLMClient:
    """Thin wrapper around an OpenAI-compatible chat completions endpoint."""

    def __init__(self, base_url: str | None = None, api_key: str | None = None, model: str | None = None) -> None:
        self.base_url = (base_url or config.LLM_BASE_URL).rstrip("/")
        self.api_key = api_key or config.LLM_API_KEY
        self.model = model or config.LLM_MODEL

    def chat_completion(self, messages: list[dict[str, Any]], tools: list[dict[str, Any]],
                        timeout_seconds: float = 360,
                        tool_choice: str | dict[str, Any] = "auto") -> ToolCall:
        """Call the LLM and return the next tool call."""
        if not self.base_url:
            raise LLMClientError("HERMES_LLM_BASE_URL is not configured")
        if not self.api_key:
            raise LLMClientError("HERMES_LLM_API_KEY is not configured")

        payload: dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            "tools": tools,
            "tool_choice": tool_choice,
            "max_tokens": config.LLM_MAX_OUTPUT_TOKENS,
            "chat_template_kwargs": {"enable_thinking": config.LLM_ENABLE_THINKING},
        }

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }

        deadline = time.monotonic() + timeout_seconds
        last_exception = None
        for attempt in range(3):
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise LLMClientError("LLM run time budget exhausted")
            try:
                response = requests.post(
                    f"{self.base_url}/chat/completions",
                    headers=headers,
                    json=payload,
                    timeout=(
                        min(5, remaining),
                        min(config.LLM_REQUEST_TIMEOUT_SECONDS, remaining),
                    ),
                )
                response.raise_for_status()
                break
            except requests.HTTPError as exc:
                last_exception = exc
                status_code = exc.response.status_code if exc.response is not None else None
                logger.warning("LLM request failed (attempt %d): HTTP %s - %s", attempt + 1, status_code, exc)
                if attempt < 2:
                    time.sleep(max(0, min(2 ** attempt, deadline - time.monotonic())))
                    continue
                raise LLMClientError(f"LLM request failed: {exc}") from exc
            except requests.RequestException as exc:
                last_exception = exc
                logger.warning("LLM request failed (attempt %d): %s", attempt + 1, exc)
                if attempt < 2:
                    time.sleep(max(0, min(2 ** attempt, deadline - time.monotonic())))
                    continue
                raise LLMClientError(f"LLM request failed: {exc}") from exc
        else:
            raise LLMClientError(f"LLM request failed after retries: {last_exception}")

        try:
            data = response.json()
        except json.JSONDecodeError as exc:
            raise LLMClientError("Invalid JSON response from LLM") from exc

        choice = data.get("choices", [{}])[0]
        message = choice.get("message", {})
        tool_calls = message.get("tool_calls")

        if not tool_calls:
            raise LLMClientError(
                "LLM did not return a tool call "
                f"(message_keys={sorted(message.keys())}, "
                f"content_present={bool(message.get('content'))}, "
                f"reasoning_present={bool(message.get('reasoning_content'))})",
                retryable_tool_response=True,
            )

        first_call = tool_calls[0]
        name = first_call.get("function", {}).get("name", "")
        arguments_str = first_call.get("function", {}).get("arguments", "{}")

        try:
            arguments = json.loads(arguments_str)
        except json.JSONDecodeError as exc:
            raise LLMClientError(
                "Invalid tool call arguments JSON",
                retryable_tool_response=True,
            ) from exc

        if not isinstance(arguments, dict):
            raise LLMClientError("Tool arguments must be a JSON object")

        logger.debug("LLM chose tool: %s", name)
        return ToolCall(name=name, arguments=arguments)


class LLMClientError(Exception):
    """Raised when the LLM client cannot obtain a valid tool call."""

    def __init__(self, message: str, retryable_tool_response: bool = False) -> None:
        super().__init__(message)
        self.retryable_tool_response = retryable_tool_response
