"""Browser tools exposed to the LLM agent.

Each tool validates inputs and routes all navigations through BrowserGuard.
"""

from __future__ import annotations

import hashlib
import logging
import re
from dataclasses import dataclass, field
from typing import TYPE_CHECKING, Any, Callable
from urllib.parse import parse_qs, urljoin, urlsplit

from browser_guard import BrowserGuard, BrowserGuardError


if TYPE_CHECKING:
    from playwright.sync_api import Page


logger = logging.getLogger(__name__)


@dataclass
class ProductCandidate:
    """Minimal product candidate extracted from a page."""
    url: str
    title: str
    price: str
    original_price: str | None = None
    image_url: str | None = None
    metadata_checked: bool = False
    tag: str | None = None
    """The single niche (from the strategy's configured tags) closest to this
    product, as chosen by the LLM. None when only 0-1 tags are configured
    (no ambiguity) or when the LLM couldn't confidently pick one — callers
    should fall back to applying every configured tag in that case."""
    rating: str | None = None
    """Visible star rating (e.g. "4.5"), as reported by the LLM from the
    listing or backfilled from the product page's own JSON-LD
    aggregateRating — never invented when not clearly shown."""
    rating_count: str | None = None
    """Visible review/rating count (e.g. "1234"), same sourcing as rating."""
    sales_count: str | None = None
    """Visible historical sales signal (e.g. "1000" from "Mais de 1000
    vendidos"). Listing-only — marketplaces don't expose this declaratively,
    so it is never backfilled from product metadata."""
    official_store: bool | None = None
    """True only when the listing carries an explicit official-store/sold-by
    label (loja oficial, vendido pela {marketplace}, MercadoLíder
    Platinum/Gold, etc). Listing-only, and null (not False) when no such
    label is visible — the LLM is instructed never to infer this."""


@dataclass
class BrowserToolResult:
    """Result from executing a browser tool."""
    success: bool
    message: str
    data: dict[str, Any] = field(default_factory=dict)


class BrowserToolSet:
    """Explicit, guarded browser tools for LLM-driven discovery."""

    ALLOWED_TOOL_NAMES = frozenset({
        "navigate",
        "inspect_page",
        "click",
        "go_back",
        "add_product_candidate",
        "get_product_metadata",
        "finish",
    })

    def __init__(
        self,
        page: Page,
        guard: BrowserGuard,
        candidate_validator: Callable[[ProductCandidate], tuple[bool, str]] | None = None,
        metadata_resolver: Callable[[str, str], dict[str, Any]] | None = None,
        target_candidates: int | None = None,
        required_urls: list[str] | None = None,
    ) -> None:
        self.page = page
        self.guard = guard
        self.candidate_validator = candidate_validator
        self.metadata_resolver = metadata_resolver
        self.target_candidates = target_candidates
        self.required_urls = required_urls or []
        self.page_count = 0
        self.candidates: list[ProductCandidate] = []
        self.visited_urls: set[str] = set()

    def validate_tool_name(self, name: str) -> None:
        """Raise if the tool name is not in the allowlist."""
        if name not in self.ALLOWED_TOOL_NAMES:
            raise ToolNotAllowedError(f"Tool not allowed: {name}")

    def execute(self, tool_name: str, arguments: dict[str, Any]) -> BrowserToolResult:
        """Execute a tool by name and return the result."""
        self.validate_tool_name(tool_name)
        handler = getattr(self, f"_tool_{tool_name}")
        return handler(**arguments)

    def _tool_navigate(self, url: str) -> BrowserToolResult:
        """Navigate to a URL, validated by BrowserGuard."""
        try:
            validated_url = self.guard.validate_url(url)
            if not self.guard.installed:
                self.guard.install(self.page)
            
            response = self.page.goto(validated_url, wait_until="domcontentloaded")
            self.page.wait_for_timeout(2500)
            self.page_count += 1
            self.visited_urls.add(self._normalize_url(validated_url))
            if isinstance(self.page.url, str):
                self.visited_urls.add(self._normalize_url(self.page.url))
            
            status = response.status if response else 0
            title = self.page.title()
            
            return BrowserToolResult(
                success=True,
                message=f"Navigation successful: {validated_url}",
                data={
                    "final_url": self.page.url,
                    "status": status,
                    "title": title,
                    "page_count": self.page_count,
                },
            )
        except BrowserGuardError as exc:
            logger.warning("Navigation blocked by BrowserGuard: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Navigation blocked: {exc}",
            )
        except Exception as exc:
            logger.error("Navigation failed: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Navigation failed: {exc}",
            )

    def _tool_inspect_page(self) -> BrowserToolResult:
        """Return clean visible text and clickable links from the current page."""
        try:
            # Text observation and accessibility roles; no marketplace HTML selectors.
            text = self.page.evaluate("() => document.body.innerText") or ""
            links = self.page.get_by_role("link").evaluate_all("""
                elements => elements.map(a => ({
                    text: (a.innerText || a.getAttribute('aria-label') || '').trim(),
                    url: a.href,
                    disabled: a.getAttribute('aria-disabled') === 'true'
                })).filter(a => a.text && a.url && a.url.startsWith('https://'))
            """)
            buttons = self.page.get_by_role("button").evaluate_all("""
                elements => elements.map(b => ({
                    text: (b.getAttribute('aria-label') || b.innerText || '').trim(),
                    disabled: b.disabled || b.getAttribute('aria-disabled') === 'true'
                })).filter(b => b.text)
            """)
            images = self.page.get_by_role("img").evaluate_all("""
                elements => elements.map(image => {
                    const rect = image.getBoundingClientRect();
                    return {
                        src: image.currentSrc || image.src || '',
                        alt: (image.alt || image.getAttribute('aria-label') || image.getAttribute('title') || '').trim(),
                        order: elements.indexOf(image),
                        width: image.naturalWidth || rect.width || 0,
                        height: image.naturalHeight || rect.height || 0,
                        visible: rect.width > 0 && rect.height > 0,
                    };
                }).filter(image => image.src)
            """)

            # Preserve the semantic relationship between a product link and
            # the image it exposes. A flat image list is ambiguous on
            # marketplaces with many cards, while nested role locators let
            # the LLM associate the correct picture without CSS/HTML
            # selectors.
            link_locators = self.page.get_by_role("link")
            links_by_url = {
                link.get_attribute("href"): link
                for index in range(link_locators.count())
                if (link := link_locators.nth(index)).get_attribute("href")
            }
            observed_image_urls = {
                image.get("src") for image in images
                if isinstance(image, dict) and image.get("src")
            }
            for link in links:
                link_url = link.get("url") if isinstance(link, dict) else None
                semantic_link = links_by_url.get(link_url)
                if semantic_link is None:
                    continue
                link_images = semantic_link.get_by_role("img")
                for image_index in range(link_images.count()):
                    image = link_images.nth(image_index)
                    image_url = image.evaluate(
                        "element => element.currentSrc || element.src || "
                        "element.getAttribute('data-src') || element.getAttribute('data-lazy-src') || ''"
                    )
                    if not isinstance(image_url, str) or not image_url.startswith(("http://", "https://")):
                        continue
                    link["image_url"] = image_url
                    if image_url not in observed_image_urls:
                        images.append({
                            "src": image_url,
                            "alt": image.get_attribute("alt") or "",
                            "order": len(images),
                            "width": 0,
                            "height": 0,
                            "visible": True,
                        })
                        observed_image_urls.add(image_url)
                    break

            return BrowserToolResult(
                success=True,
                message="Page observed through visible text and accessibility roles",
                data={
                    "url": self.page.url,
                    "title": self.page.title(),
                    "text": text,
                    "links": links,
                    "buttons": buttons,
                    "images": images,
                },
            )
        except Exception as exc:
            logger.error("Page inspection failed: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Inspection failed: {exc}",
            )

    def _tool_click(self, text: str) -> BrowserToolResult:
        """Click an observed link or button by its exact accessible name."""
        try:
            # Install guard if not already installed
            if not self.guard.installed:
                self.guard.install(self.page)

            element = self.page.get_by_role("link", name=text, exact=True).first
            if element.count() == 0:
                element = self.page.get_by_role("button", name=text, exact=True).first

            if element.count() == 0:
                return BrowserToolResult(
                    success=False,
                    message=f"Click failed: no element found with visible text '{text}'",
                )

            previous_url = self.page.url

            # Playwright waits for actionable controls; AJAX pagination need not trigger navigation.
            element.click(timeout=5000)

            self.page.wait_for_timeout(1500)

            if isinstance(self.page.url, str) and self.page.url != previous_url:
                self.page_count += 1
                self.visited_urls.add(self._normalize_url(self.page.url))

            return BrowserToolResult(
                success=True,
                message=f"Click successful on: {text}",
                data={
                    "final_url": self.page.url,
                    "title": self.page.title(),
                },
            )
        except Exception as exc:
            logger.error("Click failed: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Click failed: {exc}",
            )

    def _tool_go_back(self) -> BrowserToolResult:
        """Navigate back in browser history."""
        try:
            self.page.go_back()
            self.page.wait_for_timeout(1500)
            
            return BrowserToolResult(
                success=True,
                message="Navigated back",
                data={
                    "final_url": self.page.url,
                    "title": self.page.title(),
                },
            )
        except Exception as exc:
            logger.error("Go back failed: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Go back failed: {exc}",
            )

    def _tool_get_product_metadata(self, url: str) -> BrowserToolResult:
        """Extract product metadata from a product page using schema.org and Open Graph."""
        try:
            validated_url = self.guard.validate_url(url, navigation=True)
            if not self.guard.installed:
                self.guard.install(self.page)

            response = self.page.goto(validated_url, wait_until="domcontentloaded")
            self.page.wait_for_timeout(2500)
            status = response.status if response else 0

            raw = self.page.evaluate("""
                () => {
                    const result = {
                        title: document.title,
                        url: document.location.href,
                        og: {},
                        twitter: {},
                        json_ld: [],
                        image_src: null
                    };

                    for (const meta of document.head.children) {
                        const key = meta.getAttribute('property') || meta.getAttribute('name') || '';
                        if (key.startsWith('og:') || key.startsWith('product:'))
                            result.og[key] = meta.getAttribute('content');
                        if (key.startsWith('twitter:')) result.twitter[key] = meta.getAttribute('content');
                        if (meta.getAttribute('rel') === 'image_src') result.image_src = meta.getAttribute('href');
                    }
                    for (const script of document.scripts) {
                        if (script.type !== 'application/ld+json') continue;
                        try { result.json_ld.push(JSON.parse(script.textContent)); } catch (e) {}
                    }

                    return result;
                }
            """)

            metadata = self._parse_product_metadata(raw)
            accessible_images = self.page.get_by_role("img").evaluate_all("""
                elements => elements.map(image => {
                    const rect = image.getBoundingClientRect();
                    return {
                        src: image.currentSrc || image.src || '',
                        alt: (image.alt || image.getAttribute('aria-label') || image.getAttribute('title') || '').trim(),
                        order: elements.indexOf(image),
                        width: image.naturalWidth || rect.width || 0,
                        height: image.naturalHeight || rect.height || 0,
                        visible: rect.width > 0 && rect.height > 0,
                    };
                })
            """)
            # Prefer the first semantically exposed product image. Declarative
            # metadata may contain a banner or recommendation image.
            accessible_product_image = self._select_accessible_image(accessible_images)
            if not accessible_product_image:
                # Some marketplaces expose the main image with a small
                # rendered box while its actual resource is still valid. Keep
                # the semantic/non-review filters, but relax only the size
                # threshold as a generic fallback.
                accessible_product_image = self._select_accessible_image(
                    accessible_images, minimum_area=10_000,
                )
            if accessible_product_image:
                metadata["image"] = accessible_product_image
            else:
                metadata["image"] = self._normalize_image_url(metadata.get("image"), raw.get("url"))
                if not self._is_likely_product_image(metadata.get("image")):
                    metadata["image"] = None
            logger.info(
                "Product metadata image resolution: found=%s accessible=%d",
                bool(metadata.get("image")), len(accessible_images),
            )
            visible_text = ""
            if self.metadata_resolver is not None and (
                not metadata.get("price") or not metadata.get("original_price")
            ):
                visible_text = self.page.evaluate("() => document.body.innerText") or ""

            return BrowserToolResult(
                success=True,
                message=f"Product metadata extracted (HTTP {status})",
                data={
                    "url": raw.get("url"),
                    "status": status,
                    "title": metadata.get("title"),
                    "price": metadata.get("price"),
                    "original_price": metadata.get("original_price"),
                    "image": metadata.get("image"),
                    "description": metadata.get("description"),
                    "availability": metadata.get("availability"),
                    "rating": metadata.get("rating"),
                    "rating_count": metadata.get("rating_count"),
                    "visible_text": visible_text,
                },
            )
        except BrowserGuardError as exc:
            logger.warning("Metadata extraction blocked by BrowserGuard: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Metadata extraction blocked: {exc}",
            )
        except Exception as exc:
            logger.error("Failed to extract product metadata: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Failed to extract product metadata: {exc}",
            )

    @staticmethod
    def _normalize_image_url(src: Any, base_url: str | None = None) -> str | None:
        """Normalize absolute and protocol-relative declarative image URLs."""
        if not isinstance(src, str) or not src.strip():
            return None
        value = src.strip()
        if value.startswith("//"):
            return "https:" + value
        if value.startswith(("http://", "https://")):
            return value
        if not base_url:
            return None
        normalized = urljoin(base_url, value)
        return normalized if normalized.startswith(("http://", "https://")) else None

    @staticmethod
    def _is_offer_specific_url(url: str) -> bool:
        """Whether a URL identifies a particular offer inside a product page."""
        try:
            parsed = urlsplit(url)
            query = parse_qs(parsed.query, keep_blank_values=True)
        except (TypeError, ValueError):
            return False

        # Product/catalog pages can expose structured prices for a different
        # seller or payment condition. Keep the listing price when the URL
        # explicitly carries an offer/deal identity (e.g. Mercado Livre's
        # `wid` and `deal_print_id` parameters).
        return bool(
            {"wid", "deal_id", "deal_print_id"}.intersection(query)
            or "deal:" in parsed.query.lower()
        )

    @staticmethod
    def _is_likely_product_image(src: str | None) -> bool:
        """Reject known marketplace banners and non-product image paths."""
        src = BrowserToolSet._normalize_image_url(src)
        if not src:
            return False

        lowered = src.lower()
        excluded_paths = ("/digital/video/", "/merch/", "/sprite", "/logo", "/icon")
        if any(path in lowered for path in excluded_paths):
            return False

        # Amazon's product gallery uses image paths; G/32 is commonly a
        # promotional banner rather than the advertised product.
        if "amazon.com" in lowered and "/images/g/" in lowered:
            return False

        return True

    @staticmethod
    def _select_accessible_image(
        images: list[dict[str, Any]], minimum_area: float = 40_000,
    ) -> str | None:
        """Select the first likely main product image from semantic image data."""
        excluded = re.compile(
            r"\b(?:logo|avatar|icon|ícone|banner|rating|estrela|star|badge|selo|"
            r"review|reviews|customer|buyer|comprador|avalia[cç][aã]o|"
            r"foto\s+(?:do\s+)?cliente|customer\s+(?:photo|image)|user\s+image|"
            r"thumbnail|miniatura|related|patrocinado|sponsored|"
            r"assistant|assistente|reacher|recommendation|recomendad)\b",
            re.I,
        )
        candidates = []
        for image in images:
            if not isinstance(image, dict) or not image.get("visible"):
                continue
            src = image.get("src")
            if not BrowserToolSet._is_likely_product_image(src):
                continue
            if excluded.search(str(image.get("alt", ""))):
                continue
            try:
                area = float(image.get("width", 0)) * float(image.get("height", 0))
            except (TypeError, ValueError):
                continue
            if area < minimum_area:
                continue
            candidates.append((image.get("order", 0), area, src))

        return min(candidates, key=lambda candidate: candidate[0])[2] if candidates else None

    def _parse_product_metadata(self, raw: dict[str, Any]) -> dict[str, Any]:
        """Parse schema.org JSON-LD and Open Graph metadata into a flat dict."""
        result: dict[str, Any] = {}
        og = raw.get("og", {})
        twitter = raw.get("twitter", {})
        json_ld = raw.get("json_ld", [])
        declarative_image = (
            og.get("og:image:secure_url")
            or og.get("og:image")
            or og.get("image")
            or twitter.get("twitter:image:src")
            or twitter.get("twitter:image")
            or twitter.get("image")
            or raw.get("image_src")
        )
        image_candidates: list[Any] = [declarative_image]

        result["title"] = og.get("og:title") or og.get("title")
        result["description"] = og.get("og:description") or og.get("description")
        # Open Graph is the marketplace's page-preview image and is more
        # reliable here than inconsistent Product JSON-LD image values.
        result["image"] = declarative_image
        price_fields = ["og:price:amount", "price:amount", "og:price"]
        for field in price_fields:
            if og.get(field):
                result["price"] = str(og[field])
                break

        for item in json_ld:
            if not isinstance(item, dict):
                continue

            graph = item.get("@graph", [])
            candidates = [item]
            if isinstance(graph, list):
                candidates.extend(g for g in graph if isinstance(g, dict))

            for candidate in candidates:
                ctype = candidate.get("@type")
                if isinstance(ctype, str):
                    ctype = [ctype]
                if not isinstance(ctype, list):
                    continue

                if "Product" not in ctype:
                    continue

                if not result.get("title"):
                    result["title"] = candidate.get("name") or candidate.get("headline")
                if not result.get("description"):
                    result["description"] = candidate.get("description")

                image = candidate.get("image")
                if image:
                    if isinstance(image, list):
                        image_candidates.extend(image)
                    else:
                        image_candidates.append(image)

                if not result.get("rating"):
                    aggregate_rating = candidate.get("aggregateRating")
                    if isinstance(aggregate_rating, dict):
                        rating_value = aggregate_rating.get("ratingValue")
                        if rating_value is not None:
                            result["rating"] = str(rating_value)
                        review_count = aggregate_rating.get("reviewCount") or aggregate_rating.get("ratingCount")
                        if review_count is not None:
                            result["rating_count"] = str(review_count)

                offers = candidate.get("offers")
                if offers:
                    if isinstance(offers, dict):
                        offers = [offers]
                    if isinstance(offers, list):
                        for offer in offers:
                            if not isinstance(offer, dict):
                                continue
                            if not result.get("price") and offer.get("price") is not None:
                                result["price"] = str(offer["price"])
                            if not result.get("availability") and offer.get("availability"):
                                result["availability"] = offer["availability"]
                            if not result.get("original_price"):
                                specs = offer.get("priceSpecification", [])
                                if isinstance(specs, dict):
                                    specs = [specs]
                                if isinstance(specs, list):
                                    for spec in specs:
                                        if (isinstance(spec, dict)
                                                and str(spec.get("priceType", "")).rsplit("/", 1)[-1] == "ListPrice"
                                                and spec.get("price") is not None):
                                            result["original_price"] = str(spec["price"])
                                            break

                if result.get("title") and result.get("price"):
                    break
            if result.get("title") and result.get("price"):
                break

        # Fallback: search any JSON-LD for an image field, including nested
        # values that are not attached directly to the Product node.
        if not result.get("image"):
            for item in json_ld:
                if not isinstance(item, dict):
                    continue
                image = item.get("image")
                if isinstance(image, list):
                    image_candidates.extend(image)
                elif image:
                    image_candidates.append(image)

        for image in image_candidates:
            if isinstance(image, dict):
                image = image.get("url")
            image = self._normalize_image_url(image, raw.get("url"))
            if self._is_likely_product_image(image):
                result["image"] = image
                break

        return {k: v for k, v in result.items() if v is not None and v != ""}

    def _tool_add_product_candidate(
        self,
        url: str,
        title: str,
        price: str,
        original_price: str | None = None,
        image_url: str | None = None,
    ) -> BrowserToolResult:
        """Add a product candidate identified by the LLM."""
        try:
            # Validate URL through BrowserGuard (treat as navigation to enforce allowlist)
            validated_url = self.guard.validate_url(url, navigation=True)

            candidate = ProductCandidate(
                url=validated_url,
                title=title,
                price=price,
                original_price=original_price,
                image_url=image_url,
            )

            if any(existing.url == validated_url for existing in self.candidates):
                return BrowserToolResult(
                    success=False,
                    message=f"Candidate discarded (duplicate URL): {title[:50]}",
                    data={
                        "discarded": True,
                        "total_candidates": len(self.candidates),
                    },
                )

            if self.candidate_validator is not None:
                is_valid, reason = self.candidate_validator(candidate)
                if not is_valid:
                    logger.info("Candidate discarded: %s - %s", title[:50], reason)
                    return BrowserToolResult(
                        success=False,
                        message=f"Candidate discarded: {reason}",
                        data={
                            "discarded": True,
                            "total_candidates": len(self.candidates),
                        },
                    )

            self.candidates.append(candidate)

            if not image_url:
                logger.warning("Candidate added without image: %s", title[:50])

            return BrowserToolResult(
                success=True,
                message=f"Added candidate: {title[:50]}{' (no image)' if not image_url else ''}",
                data={
                    "total_candidates": len(self.candidates),
                    "candidate_url": validated_url,
                    "has_image": image_url is not None,
                },
            )
        except BrowserGuardError as exc:
            logger.warning("Candidate URL blocked by BrowserGuard: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Candidate URL blocked: {exc}",
            )
        except Exception as exc:
            logger.error("Failed to add candidate: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Failed to add candidate: {exc}",
            )

    def _tool_finish(self, exhausted: bool = False, reason: str | None = None) -> BrowserToolResult:
        """Signal that the agent is done exploring."""
        target_reached = (
            self.target_candidates is None
            or len(self.candidates) >= self.target_candidates
        )
        remaining_urls = [
            url for url in self.required_urls
            if self._normalize_url(url) not in self.visited_urls
        ]

        if not target_reached and (not exhausted or remaining_urls):
            remaining = ""
            if remaining_urls:
                remaining = f" Unvisited configured URLs: {', '.join(remaining_urls)}."

            return BrowserToolResult(
                success=False,
                message=(
                    f"Cannot finish yet: {len(self.candidates)} valid candidates collected; "
                    f"target is {self.target_candidates}. Continue through pagination and configured URLs."
                    f"{remaining}"
                ),
                data={
                    "total_candidates": len(self.candidates),
                    "target_candidates": self.target_candidates,
                    "target_reached": False,
                    "exhausted": False,
                    "remaining_urls": remaining_urls,
                    "page_count": self.page_count,
                },
            )

        sources_exhausted = exhausted and not target_reached

        return BrowserToolResult(
            success=True,
            message=f"Agent finished. Total candidates: {len(self.candidates)}",
            data={
                "total_candidates": len(self.candidates),
                "target_candidates": self.target_candidates,
                "target_reached": target_reached,
                "exhausted": sources_exhausted,
                "reason": reason,
                "page_count": self.page_count,
            },
        )

    def enrich_candidates(self, should_continue: Callable[[], bool] = lambda: True) -> int:
        """Enrich collected candidates with metadata from their product pages."""
        retained_candidates = []
        discarded_count = 0

        for candidate in self.candidates:
            if not should_continue():
                break
            try:
                if not candidate.metadata_checked:
                    result = self._tool_get_product_metadata(candidate.url)
                    candidate.metadata_checked = True

                    if not result.success:
                        logger.warning("Metadata failed for %s: %s", candidate.url, result.message)
                        discarded_count += 1
                        continue
                    else:
                        data = result.data
                        if self.metadata_resolver is not None and (
                            not data.get("price") or not data.get("original_price")
                        ) and data.get("visible_text"):
                            try:
                                fallback = self.metadata_resolver(candidate.url, data["visible_text"])
                                # The textual fallback may see prices from
                                # related products, installments, or multiple
                                # offers. It may help identify a missing list
                                # price, but it must never become the source
                                # of the current price.
                                data = {
                                    **data,
                                    **{
                                        key: value for key, value in fallback.items()
                                        if key == "original_price" and value and not data.get(key)
                                    },
                                }
                                logger.info("Used visible product text fallback for %s", candidate.url)
                            except Exception as exc:
                                logger.warning("Visible product text fallback failed for %s: %s", candidate.url, exc)
                        candidate.title = data.get("title") or candidate.title
                        # Prefere o preço declarativo da página do produto ao preço
                        # extraído da listagem, que o LLM pode ter lido errado.
                        metadata_price = data.get("price")
                        if metadata_price and not self._is_offer_specific_url(candidate.url):
                            candidate.price = str(metadata_price)
                        # Only override listing image if metadata provided a better one
                        new_image = data.get("image")
                        if new_image:
                            logger.info("Image found via metadata for %s: %s", candidate.url, new_image[:80])
                            candidate.image_url = new_image
                        if data.get("original_price") and not self._is_offer_specific_url(candidate.url):
                            candidate.original_price = data["original_price"]
                        # Backfill only — the listing's own visible rating (if
                        # the LLM reported one) is at least as trustworthy as
                        # the product page's declarative data.
                        if data.get("rating") and not candidate.rating:
                            candidate.rating = str(data["rating"])
                        if data.get("rating_count") and not candidate.rating_count:
                            candidate.rating_count = str(data["rating_count"])
            except Exception as exc:
                candidate.metadata_checked = True
                logger.warning("Failed to enrich candidate %s: %s", candidate.url, exc)
                discarded_count += 1
                continue

            if self.candidate_validator is not None:
                is_valid, reason = self.candidate_validator(candidate)
                if not is_valid:
                    discarded_count += 1
                    logger.info("Candidate discarded after enrichment: %s - %s", candidate.title[:50], reason)
                    continue

            retained_candidates.append(candidate)

        self.candidates = retained_candidates

        return discarded_count

    NEXT_PAGE = re.compile(
        r"^(?:pr[óo]xim[ao](?: p[áa]gina)?|seguinte|avan[çc]ar|next(?: page)?|"
        r"(?:ver|mostrar|carregar) mais(?: produtos| resultados| ofertas)?|"
        r"(?:show|load) more(?: products| results)?|siguiente)(?:\s*[›»→>])?$",
        re.IGNORECASE,
    )

    # Patterns used to infer the current page number from the listing URL.
    PAGE_PATTERNS = [
        re.compile(r"[?&]page=(\d+)", re.IGNORECASE),
        re.compile(r"[?&]pagina=(\d+)", re.IGNORECASE),
        re.compile(r"[?&]offset=(\d+)", re.IGNORECASE),
        re.compile(r"_Page=(\d+)", re.IGNORECASE),
        re.compile(r"/(\d+)(?:/[^/]+)?/?$"),
    ]

    @staticmethod
    def fingerprint(snapshot: dict[str, Any]) -> str:
        # Ignore tracking URL churn; detect repeated listing content even across redirects.
        content = snapshot.get("text", "")
        return hashlib.sha256(content.encode()).hexdigest()

    def _current_page_number(self, url: str) -> int | None:
        """Try to infer the current page number from the listing URL."""
        for pattern in self.PAGE_PATTERNS:
            match = pattern.search(url)
            if match:
                try:
                    return int(match.group(1))
                except ValueError:
                    continue
        return None

    def _numeric_controls(self, controls: list[dict[str, Any]]) -> list[dict[str, Any]]:
        """Return controls whose visible text is a positive integer."""
        numeric = []
        for control in controls:
            text = control.get("text", "").strip()
            if text.isdecimal() and int(text) > 0:
                numeric.append(control)
        return numeric

    def _resolve_next_control(
        self,
        controls: list[dict[str, Any]],
        current_url: str,
        next_page_text: str | None,
    ) -> dict[str, Any] | None:
        """Pick the next pagination control from observed links/buttons.

        Priority:
        1. Textual next-page controls ("Próxima página", "Ver mais", etc.).
        2. Number suggested by the LLM.
        3. Next sequential number inferred from the URL or the current page set.
        """
        enabled = [c for c in controls if not c.get("disabled")]

        # 1. Textual pagination (regex match).
        control = next((c for c in enabled if self.NEXT_PAGE.fullmatch(c["text"].strip())), None)
        if control is not None:
            return control

        # 2. LLM-suggested number or phrase.
        if next_page_text:
            if next_page_text.isdecimal() or self.NEXT_PAGE.fullmatch(next_page_text):
                control = next((c for c in enabled if c["text"] == next_page_text), None)
                if control is not None:
                    return control

        # 3. Numeric pagination: pick the next sequential page number.
        numeric_controls = self._numeric_controls(enabled)
        if numeric_controls:
            current_page = self._current_page_number(current_url)
            numbers = {int(c["text"]) for c in numeric_controls}
            next_page = None
            if current_page is not None:
                next_page = current_page + 1 if (current_page + 1) in numbers else None
            if next_page is None:
                # Fallback: assume we are on the first page if "1" is present,
                # otherwise trust the smallest page number available.
                if 1 in numbers:
                    next_page = 2 if 2 in numbers else None
                if next_page is None:
                    next_page = min(numbers) if numbers else None
            if next_page is not None:
                return next(c for c in numeric_controls if int(c["text"]) == next_page)

        return None

    def _try_scroll_for_more(
        self, before: str, should_continue: Callable[[], bool],
    ) -> BrowserToolResult:
        """Scroll to load more products when pagination click is not available or failed."""
        bottom_checks = 0
        for _ in range(40):
            if not should_continue():
                return BrowserToolResult(False, "Run limit reached", {"state": "blocked"})
            self.page.mouse.wheel(0, 900)
            self.page.wait_for_timeout(750)
            observed = self._tool_inspect_page()
            if not observed.success:
                return BrowserToolResult(False, observed.message, {"state": "blocked"})
            if self.fingerprint(observed.data) != before:
                return BrowserToolResult(True, "New content loaded by scrolling",
                                         {"state": "advanced", "snapshot": observed.data})
            at_bottom = self.page.evaluate(
                "() => window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 5"
            )
            bottom_checks = bottom_checks + 1 if at_bottom else 0
            if bottom_checks >= 2:
                return BrowserToolResult(True, "No next page or new content after scrolling",
                                         {"state": "exhausted"})
        return BrowserToolResult(False, "Scrolling limit reached", {"state": "stalled"})

    def advance_listing(
        self, snapshot: dict[str, Any], next_page_text: str | None = None,
        should_continue: Callable[[], bool] = lambda: True,
    ) -> BrowserToolResult:
        """Advance through observed pagination; fall back to lazy-loading scroll when click fails."""
        controls = snapshot.get("links", []) + snapshot.get("buttons", [])
        before = self.fingerprint(snapshot)
        next_control = self._resolve_next_control(controls, snapshot.get("url", ""), next_page_text)

        if next_control:
            if not should_continue():
                return BrowserToolResult(False, "Run limit reached", {"state": "blocked"})
            clicked = self._tool_click(next_control["text"])
            if clicked.success:
                observed = self._tool_inspect_page()
                if not observed.success or self.fingerprint(observed.data) == before:
                    return BrowserToolResult(False, "Pagination did not change listing content", {"state": "stalled"})
                return BrowserToolResult(True, "Advanced via " + next_control["text"],
                                         {"state": "advanced", "snapshot": observed.data})
            # Click failed: the control may be a category expander or otherwise non-actionable.
            # Fall back to scrolling so lazy-loaded products can still be discovered.
            logger.info("Pagination click failed ('%s'), falling back to scroll", next_control["text"])
            return self._try_scroll_for_more(before, should_continue)

        return self._try_scroll_for_more(before, should_continue)

    def restore_page(self, url: str) -> bool:
        """Return to the listing page after candidate metadata enrichment."""
        try:
            self.guard.safe_navigate(self.page, url, wait_until="domcontentloaded")
            self.page.wait_for_timeout(1500)

            return True
        except Exception as exc:
            logger.warning("Failed to restore listing page %s: %s", url, exc)

            return False

    @staticmethod
    def _normalize_url(url: str) -> str:
        return url.strip().rstrip("/")


class ToolNotAllowedError(Exception):
    """Raised when an attempt is made to use a non-allowlisted tool."""
