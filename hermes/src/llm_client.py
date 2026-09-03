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

    def chat_completion(self, messages: list[dict[str, Any]], tools: list[dict[str, Any]]) -> ToolCall:
        """Call the LLM and return the next tool call."""
        if not self.base_url:
            raise LLMClientError("HERMES_LLM_BASE_URL is not configured")
        if not self.api_key:
            raise LLMClientError("HERMES_LLM_API_KEY is not configured")

        payload: dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            "tools": tools,
            "tool_choice": "auto",
        }

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json",
        }

        last_exception = None
        for attempt in range(3):
            try:
                response = requests.post(
                    f"{self.base_url}/chat/completions",
                    headers=headers,
                    json=payload,
                    timeout=120,
                )
                response.raise_for_status()
                break
            except requests.HTTPError as exc:
                last_exception = exc
                status_code = exc.response.status_code if exc.response else None
                logger.warning("LLM request failed (attempt %d): HTTP %s - %s", attempt + 1, status_code, exc)
                if attempt < 2:
                    time.sleep(2 ** attempt)
                    continue
                raise LLMClientError(f"LLM request failed: {exc}") from exc
            except requests.RequestException as exc:
                last_exception = exc
                logger.warning("LLM request failed (attempt %d): %s", attempt + 1, exc)
                if attempt < 2:
                    time.sleep(2 ** attempt)
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
            raise LLMClientError(f"LLM did not return a tool call: {message}")

        first_call = tool_calls[0]
        name = first_call.get("function", {}).get("name", "")
        arguments_str = first_call.get("function", {}).get("arguments", "{}")

        try:
            arguments = json.loads(arguments_str)
        except json.JSONDecodeError as exc:
            raise LLMClientError(f"Invalid tool call arguments JSON: {arguments_str}") from exc

        logger.debug("LLM chose tool: %s", name)
        return ToolCall(name=name, arguments=arguments)


class LLMClientError(Exception):
    """Raised when the LLM client cannot obtain a valid tool call."""
