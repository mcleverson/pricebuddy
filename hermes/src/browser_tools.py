"""Browser tools exposed to the LLM agent.

Each tool validates inputs and routes all navigations through BrowserGuard.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import TYPE_CHECKING, Any

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

    def __init__(self, page: Page, guard: BrowserGuard) -> None:
        self.page = page
        self.guard = guard
        self.page_count = 0
        self.candidates: list[ProductCandidate] = []

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
            # Extract visible text, split into paragraphs, deduplicate, and limit
            body_text = self.page.locator("body").inner_text()
            if body_text:
                lines = [line.strip() for line in body_text.split('\n') if line.strip()]
                # Remove consecutive duplicates
                deduped_lines = []
                for line in lines:
                    if not deduped_lines or line != deduped_lines[-1]:
                        deduped_lines.append(line)
                # Join and limit to avoid token explosion
                text_preview = '\n'.join(deduped_lines[:150])
            else:
                text_preview = ""

            # Extract all links with visible text
            links = self.page.evaluate("""
                () => Array.from(document.querySelectorAll('a[href]'))
                    .filter(a => a.href && !a.href.startsWith('javascript:'))
                    .map(a => ({
                        text: a.innerText.trim().substring(0, 100),
                        url: a.href
                    }))
                    .filter(link => link.url && link.url.startsWith('http') && link.text)
                    .slice(0, 60)
            """)

            # Extract product links (URLs with /dp/ or /gp/product/) with context and nearby image
            product_links = self.page.evaluate("""
                () => {
                    const seen = new Set();
                    const links = Array.from(document.querySelectorAll('a[href]'))
                        .filter(a => a.href && (a.href.includes('/dp/') || a.href.includes('/gp/product/')))
                        .map(a => {
                            const parent = a.parentElement;
                            let context = '';
                            if (parent) {
                                context = parent.innerText.trim().substring(0, 250);
                            }
                            // Look for a product image nearby (parent/grandparent or siblings)
                            let imageUrl = null;
                            let node = a;
                            for (let i = 0; i < 5 && node; i++) {
                                let img = node.querySelector('img[src*="images"], img[src*="media-amazon"], img[src*="amazon.com/images"]');
                                if (!img && node.previousElementSibling) {
                                    img = node.previousElementSibling.querySelector('img[src*="images"], img[src*="media-amazon"], img[src*="amazon.com/images"]');
                                }
                                if (!img && node.nextElementSibling) {
                                    img = node.nextElementSibling.querySelector('img[src*="images"], img[src*="media-amazon"], img[src*="amazon.com/images"]');
                                }
                                if (img) {
                                    imageUrl = img.src || img.getAttribute('data-src') || img.getAttribute('data-source') || null;
                                }
                                if (imageUrl) break;
                                node = node.parentElement;
                            }
                            return {
                                text: a.innerText.trim().substring(0, 100),
                                url: a.href,
                                context: context,
                                image_url: imageUrl
                            };
                        })
                        .filter(link => link.text && link.text.length > 5)
                        .filter(link => {
                            if (seen.has(link.url)) return false;
                            seen.add(link.url);
                            return true;
                        })
                        .slice(0, 30);
                    return links;
                }
            """)

            return BrowserToolResult(
                success=True,
                message="Page inspection completed",
                data={
                    "url": self.page.url,
                    "title": self.page.title(),
                    "text_preview": text_preview,
                    "links": links,
                    "link_count": len(links),
                    "product_links": product_links,
                    "product_link_count": len(product_links),
                    "image_hint": "Cada product_link pode conter image_url. Use-a como image_url ao chamar add_product_candidate. Se não houver image_url, a imagem será obtida posteriormente na página do produto.",
                },
            )
        except Exception as exc:
            logger.error("Page inspection failed: %s", exc)
            return BrowserToolResult(
                success=False,
                message=f"Inspection failed: {exc}",
            )

    def _tool_click(self, text: str) -> BrowserToolResult:
        """Click an element by its visible text (case-insensitive partial match)."""
        try:
            # Install guard if not already installed
            if not self.guard.installed:
                self.guard.install(self.page)

            escaped = text.replace('"', '\\"')
            element = self.page.locator(f"*:has-text(\"{escaped}\")").first

            if element is None or element.count() == 0:
                return BrowserToolResult(
                    success=False,
                    message=f"Click failed: no element found with visible text '{text}'",
                )

            # Click and wait for potential navigation
            try:
                with self.page.expect_event("requestfinished", timeout=10000) as event_info:
                    element.click(timeout=5000)
            except TimeoutError:
                # Click succeeded but no navigation request finished within timeout
                pass

            self.page.wait_for_timeout(1500)

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

            self.page.goto(validated_url, wait_until="domcontentloaded")
            self.page.wait_for_timeout(2500)

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

                    document.querySelectorAll('meta[property^="og:"], meta[name^="og:"]').forEach(meta => {
                        const key = meta.getAttribute('property') || meta.getAttribute('name');
                        if (key) result.og[key] = meta.getAttribute('content');
                    });

                    document.querySelectorAll('meta[property^="twitter:"], meta[name^="twitter:"]').forEach(meta => {
                        const key = meta.getAttribute('property') || meta.getAttribute('name');
                        if (key) result.twitter[key] = meta.getAttribute('content');
                    });

                    const imageSrc = document.querySelector('link[rel="image_src"]');
                    if (imageSrc) result.image_src = imageSrc.getAttribute('href');

                    document.querySelectorAll('script[type="application/ld+json"]').forEach(script => {
                        try {
                            result.json_ld.push(JSON.parse(script.innerText));
                        } catch (e) {}
                    });

                    return result;
                }
            """)

            metadata = self._parse_product_metadata(raw)

            return BrowserToolResult(
                success=True,
                message="Product metadata extracted",
                data={
                    "url": raw.get("url"),
                    "title": metadata.get("title"),
                    "price": metadata.get("price"),
                    "original_price": metadata.get("original_price"),
                    "image": metadata.get("image"),
                    "description": metadata.get("description"),
                    "availability": metadata.get("availability"),
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

    def _parse_product_metadata(self, raw: dict[str, Any]) -> dict[str, Any]:
        """Parse schema.org JSON-LD and Open Graph metadata into a flat dict."""
        result: dict[str, Any] = {}
        og = raw.get("og", {})
        twitter = raw.get("twitter", {})
        json_ld = raw.get("json_ld", [])

        result["title"] = og.get("og:title") or og.get("title")
        result["description"] = og.get("og:description") or og.get("description")
        result["image"] = og.get("og:image") or og.get("image") or twitter.get("twitter:image") or twitter.get("image") or raw.get("image_src")

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
                if image and not result.get("image"):
                    if isinstance(image, str):
                        result["image"] = image
                    elif isinstance(image, list) and image:
                        result["image"] = image[0]
                    elif isinstance(image, dict):
                        result["image"] = image.get("url")

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
                                list_price = offer.get("priceSpecification", {}).get("priceType") if isinstance(offer.get("priceSpecification"), dict) else None
                                if not list_price and isinstance(offer.get("priceSpecification"), list):
                                    for spec in offer["priceSpecification"]:
                                        if isinstance(spec, dict) and spec.get("priceType") == "ListPrice":
                                            list_price = spec.get("price")
                                            break
                                if list_price:
                                    result["original_price"] = str(list_price)

                if result.get("title") and result.get("price"):
                    break
            if result.get("title") and result.get("price"):
                break

        # Fallback: search any JSON-LD for an image field
        if not result.get("image"):
            for item in json_ld:
                if not isinstance(item, dict):
                    continue
                image = item.get("image")
                if isinstance(image, str) and image.startswith("http"):
                    result["image"] = image
                    break
                elif isinstance(image, list) and image and isinstance(image[0], str) and image[0].startswith("http"):
                    result["image"] = image[0]
                    break
                elif isinstance(image, dict) and image.get("url", "").startswith("http"):
                    result["image"] = image["url"]
                    break

        # Amazon-specific fallback: look for the main image in the page via data
        if not result.get("image"):
            try:
                page_image = self.page.evaluate("""
                    () => {
                        const img = document.querySelector('#landingImage, #imgBlkFront, #ebooksImg, .a-dynamic-image');
                        if (img) {
                            return img.src || img.getAttribute('data-old-hires') || img.getAttribute('data-a-dynamic-image') || null;
                        }
                        return null;
                    }
                """)
                if page_image and isinstance(page_image, str) and page_image.startswith("http"):
                    result["image"] = page_image
            except Exception:
                pass

        # Fallback 2: find the largest visible image on the page
        if not result.get("image"):
            try:
                page_image = self.page.evaluate("""
                    () => {
                        const images = Array.from(document.querySelectorAll('img'));
                        let best = null;
                        let bestArea = 0;
                        for (const img of images) {
                            const rect = img.getBoundingClientRect();
                            const area = rect.width * rect.height;
                            if (area > bestArea && img.src && img.src.startsWith('http')) {
                                bestArea = area;
                                best = img.src;
                            }
                        }
                        return best;
                    }
                """)
                if page_image and isinstance(page_image, str) and page_image.startswith("http"):
                    result["image"] = page_image
            except Exception:
                pass

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

    def _tool_finish(self) -> BrowserToolResult:
        """Signal that the agent is done exploring."""
        return BrowserToolResult(
            success=True,
            message=f"Agent finished. Total candidates: {len(self.candidates)}",
            data={
                "total_candidates": len(self.candidates),
                "page_count": self.page_count,
            },
        )

    def enrich_candidates(self) -> None:
        """Enrich collected candidates with metadata from their product pages."""
        for candidate in self.candidates:
            try:
                result = self._tool_get_product_metadata(candidate.url)
                if not result.success:
                    logger.warning("Metadata failed for %s: %s", candidate.url, result.message)
                    continue

                data = result.data
                candidate.title = data.get("title") or candidate.title
                # Prefere o preço declarativo da página do produto ao preço
                # extraído da listagem, que o LLM pode ter lido errado.
                metadata_price = data.get("price")
                if metadata_price:
                    candidate.price = str(metadata_price)
                # Only override listing image if metadata provided a better one
                new_image = data.get("image")
                if new_image:
                    logger.info("Image found via metadata for %s: %s", candidate.url, new_image[:80])
                    candidate.image_url = new_image
                if not candidate.original_price:
                    candidate.original_price = data.get("original_price")
            except Exception as exc:
                logger.warning("Failed to enrich candidate %s: %s", candidate.url, exc)


class ToolNotAllowedError(Exception):
    """Raised when an attempt is made to use a non-allowlisted tool."""
