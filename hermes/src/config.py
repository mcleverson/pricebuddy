"""Centralized configuration for Hermes agent."""

from __future__ import annotations

import os


# LLM Configuration
LLM_BASE_URL = os.environ.get("HERMES_LLM_BASE_URL", "")
LLM_API_KEY = os.environ.get("HERMES_LLM_API_KEY", "")
LLM_MODEL = os.environ.get("HERMES_LLM_MODEL", "gpt-4o-mini")

# Browser Configuration
ALLOWED_HOSTS = os.environ.get("HERMES_ALLOWED_HOSTS", "")
HTTP_PROXY = os.environ.get("HERMES_HTTP_PROXY", "")
USE_CHROME = os.environ.get("HERMES_USE_CHROME", "true").lower() == "true"

# Hard Limits
MAX_STEPS = int(os.environ.get("HERMES_MAX_STEPS", "30"))
MAX_PAGES = int(os.environ.get("HERMES_MAX_PAGES", "15"))
MAX_RAW_CANDIDATES = int(os.environ.get("HERMES_MAX_RAW_CANDIDATES", "10"))
MAX_SELECTED_CANDIDATES = int(os.environ.get("HERMES_MAX_SELECTED_CANDIDATES", "10"))
RUN_TIMEOUT_SECONDS = int(os.environ.get("HERMES_RUN_TIMEOUT_SECONDS", "600"))

# PriceBuddy API Configuration
PRICEBUDDY_API_BASE_URL = os.environ.get("PRICEBUDDY_API_BASE_URL", "")
PRICEBUDDY_API_TOKEN = os.environ.get("PRICEBUDDY_API_TOKEN", "")
HERMES_STORE_ID = int(os.environ.get("HERMES_STORE_ID", "1"))
HERMES_MIN_DISCOUNT_PERCENTAGE = float(os.environ.get("HERMES_MIN_DISCOUNT_PERCENTAGE", "20.0"))


def redact_secrets(text: str) -> str:
    """Redact sensitive values from text for safe logging."""
    if not text:
        return text
    
    redacted = text
    for secret in (LLM_API_KEY, PRICEBUDDY_API_TOKEN):
        if secret and secret in redacted:
            redacted = redacted.replace(secret, "***REDACTED***")
    
    return redacted
