"""LLM-driven discovery agent for Hermes.

The agent iteratively explores allowed marketplace pages using browser tools
guarded by BrowserGuard, guided by an LLM that chooses the next action.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import signal
import sys
import time
from pathlib import Path
from typing import Any

import requests

import config
from browser_guard import BrowserGuard, BrowserGuardError
from browser_tools import BrowserToolSet, ProductCandidate, ToolNotAllowedError
from llm_client import LLMClient, LLMClientError

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

SYSTEM_PROMPT_TEMPLATE = """Você é o Hermes, um agente de discovery para o PriceBuddy.

## Seu objetivo
{goal}

## Marketplace
{marketplace}

## Domínios permitidos
{allowed_hosts}

## Boas superfícies iniciais (ordem OBRIGATÓRIA)
{starting_urls}

## Sinais de oportunidade
- Desconto aparente de PELO MENOS {min_discount_percentage}% (original_price > price e o desconto percentual é ≥ {min_discount_percentage}%)
- Badge "Oferta" ou "Promoção"
- Rating alto (4+ estrelas)
- Quantidade significativa de reviews
- Cupons visíveis
- Preço promocional destacado

## Limites
- Você NÃO decide se o desconto é historicamente verdadeiro (responsabilidade do PriceBuddy)
- Você coleta candidatos visíveis na página
- Você não faz login, compra ou acessa contas

## Ferramentas disponíveis
Use as ferramentas abaixo para explorar o marketplace:

1. **navigate(url)**: Navega para uma URL (deve ser allowlisted)
2. **inspect_page()**: Retorna o texto visível e os links da página atual
3. **click(texto_visivel)**: Clica em um elemento pelo TEXTO VISÍVEL
4. **go_back()**: Volta para a página anterior
5. **add_product_candidate(url, title, price, original_price)**: Adiciona um produto que você identificou na página
6. **get_product_metadata(url)**: Extrai metadados declarativos (schema.org, Open Graph) da página do produto, como imagem e preço
7. **finish()**: Finaliza a exploração

## Estratégia OBRIGATÓRIA
1. Comece SEMPRE pelas URLs listadas acima, na ordem indicada.
2. Use inspect_page() para obter o TEXTO VISÍVEL e os LINKS da página.
3. Leia o texto e identifique produtos com título, preço e URL. Para cada um, chame add_product_candidate(url, title, price, original_price).
4. Só navegue para outra página depois de registrar TODOS os produtos interessantes da página atual.
5. Use finish() quando tiver coletado {max_candidates} candidatos OU quando não houver mais produtos relevantes.

## Como identificar produtos
- Você deve LER o texto visível da página e usar a lista "product_links" retornada por inspect_page().
- "product_links" contém links de produtos com o texto do link, um trecho do contexto ao redor e, quando disponível, uma "image_url".
- Para cada produto em "product_links", extraia: título, preço atual, preço original (se visível), URL e image_url (se presente).
- Só adicione um produto se ele tiver preço original visível E o desconto percentual for de PELO MENOS {min_discount_percentage}%.
- Ao chamar add_product_candidate, SEMPRE passe o campo "image_url" usando o valor de image_url fornecido em product_links. Isso é ESSENCIAL para que o produto apareça com foto no PriceBuddy.
- Não use seletores CSS, classes, IDs ou XPath. Use apenas o texto visível e os links.
- Prefira identificar produtos diretamente da página de listagem sem clicar em cada produto.

## Navegação correta
- Para mudar de página, use click() com o TEXTO VISÍVEL exato do link.
- Após click(), sempre chame inspect_page() para obter o texto da nova página.
- Não use seletores CSS complexos. Use texto visível.

## Imagens e metadados
- A imagem do produto será obtida automaticamente via get_product_metadata(url) antes do envio final.
- Você pode usar get_product_metadata(url) para confirmar o título e preço de um produto específico, se necessário.

## Exemplo de uso correto
Após inspect_page(), se identificar:
"Fone de Ouvido Bluetooth XYZ - R$ 199,00 - De: R$ 299,00"
O desconto é de ~33%, portanto ≥ {min_discount_percentage}%. Chame:
add_product_candidate(url="https://example.com/produto", title="Fone de Ouvido Bluetooth XYZ", price="R$ 199,00", original_price="R$ 299,00")

Se o mesmo produto estiver "R$ 199,00 - De: R$ 219,00" (desconto ~9%), NÃO chame add_product_candidate, pois o desconto é menor que {min_discount_percentage}%.

## Importante
- Você deve chamar add_product_candidate() para CADA produto de interesse que encontrar que atenda ao critério de desconto ≥ {min_discount_percentage}%.
- Colete pelo menos 3 a 5 produtos por página antes de navegar, se houverem produtos com desconto ≥ {min_discount_percentage}%.
- Não registre produtos sem preço original ou com desconto menor que {min_discount_percentage}%.
- Não deixe de registrar produtos por falta de image_url (a imagem será obtida automaticamente).
- Não tente acessar URLs bloqueadas (cart, checkout, account, etc.)
- Respeite os limites impostos (steps, pages, candidates, timeout)
"""

TOOLS_SCHEMA = [
    {
        "type": "function",
        "function": {
            "name": "navigate",
            "description": "Navigate to a URL (must be allowlisted)",
            "parameters": {
                "type": "object",
                "properties": {
                    "url": {
                        "type": "string",
                        "description": "The URL to navigate to"
                    }
                },
                "required": ["url"]
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "inspect_page",
            "description": "Inspect the current page: returns visible text and clickable links. Read the text to identify products and call add_product_candidate.",
            "parameters": {
                "type": "object",
                "properties": {}
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "click",
            "description": "Click on an element by its visible text (case-insensitive partial match)",
            "parameters": {
                "type": "object",
                "properties": {
                    "text": {
                        "type": "string",
                        "description": "Visible text of the element to click"
                    }
                },
                "required": ["text"]
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "go_back",
            "description": "Navigate back to the previous page",
            "parameters": {
                "type": "object",
                "properties": {}
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "get_product_metadata",
            "description": "Extract product metadata (image, title, price, availability) from a product page using schema.org and Open Graph. Useful to confirm product details before adding a candidate.",
            "parameters": {
                "type": "object",
                "properties": {
                    "url": {
                        "type": "string",
                        "description": "Product URL to extract metadata from"
                    }
                },
                "required": ["url"]
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "add_product_candidate",
            "description": "Register a product candidate identified from the current page. Use after inspecting the page and finding a product.",
            "parameters": {
                "type": "object",
                "properties": {
                    "url": {
                        "type": "string",
                        "description": "Product URL (must be allowlisted)"
                    },
                    "title": {
                        "type": "string",
                        "description": "Product title"
                    },
                    "price": {
                        "type": "string",
                        "description": "Current price (e.g. 'R$ 499,00')"
                    },
                    "original_price": {
                        "type": "string",
                        "description": "Original price before discount, if visible"
                    }
                },
                "required": ["url", "title", "price"]
            }
        }
    },
    {
        "type": "function",
        "function": {
            "name": "finish",
            "description": "Finish the discovery session",
            "parameters": {
                "type": "object",
                "properties": {}
            }
        }
    }
]


def _parse_price(value: str | None) -> float | None:
    """Converte string de preço em float, aceitando formatos BR e EN."""
    if not value:
        return None

    # Remove símbolos de moeda, espaços e espaços inquebráveis
    cleaned = value.replace("R$", "").replace("$", "").replace("\u00a0", " ").strip()
    if not cleaned:
        return None

    # Determina o separador decimal: o separador mais à direita (',' ou '.')
    # que é seguido por menos de 3 dígitos é o decimal. O outro, se houver,
    # é separador de milhar.
    last_idx = -1
    for i in range(len(cleaned) - 1, -1, -1):
        ch = cleaned[i]
        if ch in (",", "."):
            digits_after = 0
            for j in range(i + 1, len(cleaned)):
                if cleaned[j].isdigit():
                    digits_after += 1
                else:
                    break
            if digits_after < 3:
                last_idx = i
                break

    if last_idx >= 0:
        decimal_char = cleaned[last_idx]
        if decimal_char == ",":
            cleaned = cleaned.replace(".", "").replace(",", ".")
        else:  # decimal_char == "."
            cleaned = cleaned.replace(",", "")
    else:
        cleaned = cleaned.replace(".", "").replace(",", "")

    try:
        return float(cleaned)
    except ValueError:
        return None


def send_candidates_to_pricebuddy(candidates: list[ProductCandidate], tags: list[str] | None = None) -> dict:
    """Envia candidatos coletados para a API do PriceBuddy."""
    if not candidates:
        return {"success": 0, "failed": 0}

    api_url = config.PRICEBUDDY_API_BASE_URL.rstrip("/")
    api_token = config.PRICEBUDDY_API_TOKEN
    store_id = config.HERMES_STORE_ID  # Amazon

    if not api_url or not api_token:
        logging.warning("PRICEBUDDY_API_BASE_URL ou PRICEBUDDY_API_TOKEN não configurados")
        return {"success": 0, "failed": len(candidates)}

    endpoint = f"{api_url}/discovery/candidates"
    headers = {
        "Authorization": f"Bearer {api_token}",
        "Content-Type": "application/json",
        "Accept": "application/json"
    }

    success_count = 0
    failed_count = 0

    for candidate in candidates:
        price = _parse_price(candidate.price)
        original_price = _parse_price(candidate.original_price)

        if price is None:
            failed_count += 1
            logging.warning(f"✗ Preço inválido para '{candidate.title[:50]}...': {candidate.price}")
            continue

        # Filter by minimum discount threshold
        if original_price is None or original_price <= 0:
            failed_count += 1
            logging.info(f"✗ Descartado (sem preço original): {candidate.title[:50]}...")
            continue

        discount_percentage = ((original_price - price) / original_price) * 100
        if discount_percentage < config.HERMES_MIN_DISCOUNT_PERCENTAGE:
            failed_count += 1
            logging.info(
                f"✗ Descartado (desconto {discount_percentage:.1f}% < {config.HERMES_MIN_DISCOUNT_PERCENTAGE:.1f}%): "
                f"{candidate.title[:50]}..."
            )
            continue

        # Prefer metadata-enriched image if available, fallback to candidate's own image_url
        image_url = candidate.image_url
        if not image_url:
            logging.warning("No image for candidate '%s...'", candidate.title[:50])
        else:
            logging.info("Sending image for '%s...': %s", candidate.title[:50], image_url[:80])

        payload = {
            "url": candidate.url,
            "title": candidate.title,
            "price": price,
            "original_price": original_price,
            "image": image_url,
            "store_id": store_id,
            "tags": tags or [],
        }

        try:
            response = requests.post(endpoint, json=payload, headers=headers, timeout=10)
            if response.status_code in (200, 201):
                success_count += 1
                logging.info(f"✓ Candidato enviado: {candidate.title[:50]}...")
            else:
                failed_count += 1
                logging.warning(f"✗ Falha ao enviar {candidate.title[:50]}... - Status: {response.status_code} - {response.text[:200]}")
        except Exception as e:
            failed_count += 1
            logging.warning(f"✗ Erro ao enviar {candidate.title[:50]}... - {e}")

    return {"success": success_count, "failed": failed_count}


class Agent:
    """LLM-driven browser agent for marketplace discovery."""

    def __init__(
        self,
        marketplace: str,
        goal: str,
        llm_client: LLMClient | None = None,
        max_steps: int = config.MAX_STEPS,
        max_pages: int = config.MAX_PAGES,
        max_raw_candidates: int = config.MAX_RAW_CANDIDATES,
        max_selected_candidates: int = config.MAX_SELECTED_CANDIDATES,
        run_timeout_seconds: int = config.RUN_TIMEOUT_SECONDS,
        headless: bool = True,
        tags: list[str] | None = None,
        starting_urls: list[str] | None = None,
        min_discount_percentage: float = config.HERMES_MIN_DISCOUNT_PERCENTAGE,
    ) -> None:
        self.marketplace = marketplace
        self.goal = goal
        self.llm_client = llm_client or LLMClient()
        self.max_steps = max_steps
        self.max_pages = max_pages
        self.max_raw_candidates = max_raw_candidates
        self.max_selected_candidates = max_selected_candidates
        self.run_timeout_seconds = run_timeout_seconds
        self.headless = headless
        self.tags = tags or []
        self.starting_urls = starting_urls or []
        self.min_discount_percentage = min_discount_percentage

        self.steps_log: list[dict[str, Any]] = []
        self.start_time = 0.0
        self.abort_reason: str | None = None

    def run(self) -> dict[str, Any]:
        """Execute the discovery agent loop."""
        from playwright.sync_api import sync_playwright

        self.start_time = time.time()
        setup_signal_handlers(self._handle_abort)

        try:
            with sync_playwright() as playwright:
                browser, context, page = self._launch_browser(playwright)
                try:
                    guard = BrowserGuard()
                    tools = BrowserToolSet(page, guard)
                    
                    messages = self._build_initial_messages()
                    
                    step = 0
                    finished = False
                    
                    while step < self.max_steps and not finished:
                        step += 1
                        
                        # Check timeout
                        elapsed = time.time() - self.start_time
                        if elapsed >= self.run_timeout_seconds:
                            self.abort_reason = f"Timeout: {elapsed:.1f}s >= {self.run_timeout_seconds}s"
                            logging.warning(self.abort_reason)
                            break
                        
                        # Check page limit
                        if tools.page_count >= self.max_pages:
                            self.abort_reason = f"Page limit: {tools.page_count} >= {self.max_pages}"
                            logging.warning(self.abort_reason)
                            break
                        
                        # Check candidate limit
                        if len(tools.candidates) >= self.max_raw_candidates:
                            self.abort_reason = f"Candidate limit: {len(tools.candidates)} >= {self.max_raw_candidates}"
                            logging.warning(self.abort_reason)
                            break
                        
                        # Check abort signal
                        if self.abort_reason:
                            break
                        
                        try:
                            tool_call = self.llm_client.chat_completion(messages, TOOLS_SCHEMA)
                        except LLMClientError as exc:
                            logging.error("LLM error: %s", exc)
                            self.steps_log.append({
                                "step": step,
                                "tool": None,
                                "error": str(exc),
                                "candidates_count": len(tools.candidates),
                            })
                            break
                        
                        tool_name = tool_call.name
                        tool_args = tool_call.arguments
                        
                        try:
                            tools.validate_tool_name(tool_name)
                        except ToolNotAllowedError as exc:
                            logging.warning("Tool not allowed: %s", tool_name)
                            tool_result_msg = f"Error: {exc}"
                            self.steps_log.append({
                                "step": step,
                                "tool": tool_name,
                                "arguments": self._redact_arguments(tool_args),
                                "result": tool_result_msg,
                                "candidates_count": len(tools.candidates),
                            })
                            messages.append({
                                "role": "assistant",
                                "content": None,
                                "tool_calls": [{
                                    "id": f"call_{step}",
                                    "type": "function",
                                    "function": {
                                        "name": tool_name,
                                        "arguments": json.dumps(tool_args),
                                    }
                                }]
                            })
                            messages.append({
                                "role": "tool",
                                "tool_call_id": f"call_{step}",
                                "content": tool_result_msg,
                            })
                            continue
                        
                        # Execute tool
                        result = tools.execute(tool_name, tool_args)
                        
                        # Log step
                        step_log = {
                            "step": step,
                            "tool": tool_name,
                            "arguments": self._redact_arguments(tool_args),
                            "success": result.success,
                            "result": result.message[:500],
                            "candidates_count": len(tools.candidates),
                            "guard_blocked": len(guard.blocked_requests),
                        }
                        self.steps_log.append(step_log)
                        
                        logging.info(
                            "Step %d: %s(%s) -> %s (candidates: %d)",
                            step,
                            tool_name,
                            json.dumps(self._redact_arguments(tool_args)),
                            result.message[:100],
                            len(tools.candidates),
                        )
                        
                        # Build tool result message
                        tool_result_data = {
                            "success": result.success,
                            "message": result.message,
                            **result.data,
                        }
                        tool_result_msg = json.dumps(tool_result_data)
                        
                        # Add assistant message with tool call
                        messages.append({
                            "role": "assistant",
                            "content": None,
                            "tool_calls": [{
                                "id": f"call_{step}",
                                "type": "function",
                                "function": {
                                    "name": tool_name,
                                    "arguments": json.dumps(tool_args),
                                }
                            }]
                        })
                        
                        # Add tool result
                        messages.append({
                            "role": "tool",
                            "tool_call_id": f"call_{step}",
                            "content": tool_result_msg,
                        })
                        
                        if tool_name == "finish":
                            finished = True
                    
                    # Build final report
                    report = self._build_report(tools.candidates, guard)

                    # Enrich candidates with metadata from their product pages
                    if tools.candidates:
                        logging.info("Enriquecendo candidatos com metadados das páginas de produto...")
                        tools.enrich_candidates()

                    # Envia candidatos coletados para o PriceBuddy
                    if tools.candidates:
                        logging.info("Enviando candidatos coletados para o PriceBuddy...")
                        send_result = send_candidates_to_pricebuddy(tools.candidates, tags=self.tags)
                        report["pricebuddy_submission"] = send_result
                        logging.info(
                            "Envio para PriceBuddy: %d sucesso, %d falha",
                            send_result["success"],
                            send_result["failed"],
                        )
                    
                    return report
                
                finally:
                    context.close()
                    browser.close()
        
        except Exception as exc:
            logging.exception("Agent failed: %s", exc)
            return {
                "status": "error",
                "error": str(exc),
                "candidates": [],
                "steps": self.steps_log,
            }

    def _launch_browser(self, playwright):
        """Launch Chromium with stealth and guard."""
        launch_options = {
            "headless": self.headless,
            "channel": "chrome" if config.USE_CHROME else None,
            "args": [
                "--disable-blink-features=AutomationControlled",
                "--no-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--no-first-run",
                "--no-default-browser-check",
            ],
        }
        if not launch_options["channel"]:
            del launch_options["channel"]
        
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
        
        proxy = config.HTTP_PROXY
        if proxy:
            context_options["proxy"] = {"server": proxy}
        
        # Try persistent context, fall back to non-persistent
        profile_dir = os.path.expanduser("~/.config/chromium")
        try:
            context = playwright.chromium.launch_persistent_context(
                profile_dir,
                **launch_options,
                **context_options,
            )
            browser = context.browser
        except Exception as exc:
            logging.debug("Persistent context failed (%s), using non-persistent", exc)
            browser = playwright.chromium.launch(**launch_options)
            context = browser.new_context(**context_options)
        
        context.set_default_navigation_timeout(60000)
        context.set_default_timeout(30000)
        context.add_init_script(STEALTH_JS)
        
        page = context.new_page()
        
        return browser, context, page

    def _build_initial_messages(self) -> list[dict[str, Any]]:
        """Build the initial conversation messages."""
        allowed_hosts = getattr(config, "ALLOWED_HOSTS", "")
        starting_urls_text = "\n".join(
            f"{idx + 1}. {url}" for idx, url in enumerate(self.starting_urls)
        ) if self.starting_urls else "1. (nenhuma URL inicial configurada)"

        system_prompt = SYSTEM_PROMPT_TEMPLATE.format(
            goal=self.goal,
            marketplace=self.marketplace,
            allowed_hosts=allowed_hosts,
            starting_urls=starting_urls_text,
            min_discount_percentage=self.min_discount_percentage,
            max_candidates=self.max_raw_candidates,
        )

        user_content = (
            f"Inicie a exploração do marketplace {self.marketplace}.\n"
            f"Objetivo: {self.goal}\n\n"
            f"Comece OBRIGATORIAMENTE pelas seguintes URLs, nesta ordem:\n{starting_urls_text}\n\n"
            f"Leia o texto visível, identifique produtos e chame add_product_candidate para cada um."
        )

        return [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_content},
        ]

    def _build_report(self, candidates: list[ProductCandidate], guard: BrowserGuard) -> dict[str, Any]:
        """Build the final report."""
        elapsed = time.time() - self.start_time
        
        # Limit to max_selected_candidates
        selected = candidates[:self.max_selected_candidates]
        
        candidate_dicts = []
        for c in selected:
            candidate_dicts.append({
                "url": c.url,
                "title": c.title,
                "price": c.price,
                "original_price": c.original_price,
                "image_url": c.image_url,
            })
        
        return {
            "status": "completed",
            "elapsed_seconds": round(elapsed, 2),
            "steps_executed": len(self.steps_log),
            "total_candidates": len(candidates),
            "selected_candidates": len(selected),
            "candidates": candidate_dicts,
            "guard_blocked_requests": len(guard.blocked_requests),
            "abort_reason": self.abort_reason,
            "steps": self.steps_log,
        }

    def _redact_arguments(self, args: dict[str, Any]) -> dict[str, Any]:
        """Redact sensitive values from tool arguments for logging."""
        redacted = {}
        for key, value in args.items():
            if isinstance(value, str):
                redacted[key] = config.redact_secrets(value)
            else:
                redacted[key] = value
        return redacted

    def _handle_abort(self, signum: int, frame: Any) -> None:
        """Handle SIGINT/SIGTERM to abort gracefully."""
        self.abort_reason = f"Signal received: {signum}"
        logging.warning("Abort signal received")


def setup_signal_handlers(handler) -> None:
    """Install signal handlers for graceful abort."""
    signal.signal(signal.SIGINT, handler)
    signal.signal(signal.SIGTERM, handler)


def main() -> int:
    parser = argparse.ArgumentParser(description="Run Hermes discovery agent")
    parser.add_argument(
        "--marketplace",
        default="amazon",
        help="Marketplace to explore (default: amazon)",
    )
    parser.add_argument(
        "--goal",
        required=True,
        help="Discovery goal (e.g., 'encontre boas ofertas de eletrônicos')",
    )
    parser.add_argument(
        "--headed",
        action="store_true",
        help="Show browser window (visual mode)",
    )
    parser.add_argument(
        "--tag",
        action="append",
        dest="tags",
        help="Tag/niche to attach to discovered products (can be used multiple times). "
             "Also read from HERMES_DEFAULT_TAG env var.",
    )
    parser.add_argument(
        "--urls",
        action="append",
        dest="starting_urls",
        help="Starting URL to visit (can be used multiple times).",
    )
    parser.add_argument(
        "--max-raw-candidates",
        type=int,
        default=config.MAX_RAW_CANDIDATES,
        help="Maximum raw candidates to collect before stopping.",
    )
    parser.add_argument(
        "--max-selected-candidates",
        type=int,
        default=config.MAX_SELECTED_CANDIDATES,
        help="Maximum candidates to select/send to PriceBuddy.",
    )
    parser.add_argument(
        "--min-discount-percentage",
        type=float,
        default=config.HERMES_MIN_DISCOUNT_PERCENTAGE,
        help="Minimum discount percentage to accept a candidate.",
    )
    args = parser.parse_args()

    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )

    default_tags = [t.strip() for t in (os.environ.get("HERMES_DEFAULT_TAG", "")).split(",") if t.strip()]
    tags = list(dict.fromkeys(default_tags + (args.tags or [])))

    agent = Agent(
        marketplace=args.marketplace,
        goal=args.goal,
        headless=not args.headed,
        tags=tags,
        starting_urls=args.starting_urls,
        max_raw_candidates=args.max_raw_candidates,
        max_selected_candidates=args.max_selected_candidates,
        min_discount_percentage=args.min_discount_percentage,
    )

    report = agent.run()

    # Print final report
    print("\n" + "=" * 80)
    print("DISCOVERY AGENT REPORT")
    print("=" * 80)
    print(json.dumps(report, indent=2, ensure_ascii=False))
    print("=" * 80)

    if report["status"] == "completed":
        logging.info(
            "Agent completed: %d candidates collected in %.1fs",
            report["selected_candidates"],
            report["elapsed_seconds"],
        )
        return 0
    else:
        logging.error("Agent failed: %s", report.get("error", "unknown"))
        return 1


if __name__ == "__main__":
    sys.exit(main())
