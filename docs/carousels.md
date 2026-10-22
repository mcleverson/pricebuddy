# Carousels

Carousels curates products for an Instagram carousel about one niche (e.g. "Cantinho do café"). It looks for products that sell well but that people would not come across or think of when casually browsing the niche: useful, clever or surprising products worth sharing. Unlike discovery for the deals groups, a discount is never required and never drives the ranking.

## Flow

Carousels are listed in a grid (cover, niche, status, product count) with search and a status filter. From the list you can create, **Duplicate** or **Delete** a carousel:

- **Duplicate** creates a new carousel in review with the same candidates, scores and selection, so another carousel can be made from the same products. The original keeps its approval.
- **Delete** removes the carousel and its candidates. Products already added to the catalog are kept. A carousel that is still searching cannot be deleted.

1. **Niche**: "New carousel" asks for a niche, typed or picked from a suggestion. Suggestions come from Settings > Carousels and from the user's tags.
2. **Discovery** (`DiscoverCarouselProductsJob`, queue `carousels`): the AI provider selected for *Carousel curation* first turns the niche into up to 4 concrete searches, each for a different, less obvious kind of product (for "gadgets para carro": "câmera veicular", "compressor de ar portátil"…). Without a provider, or when it fails, the niche itself is searched. The searches are listed in each store report. `CarouselDiscovery` then searches each store in turn through its existing path.
   - **API stores with discovery** (Shopee): the store's provider runs every search, with no discount, sales or rating floor. The best sellers of each search are kept first, taking turns between searches.
   - **Agentic stores** (Amazon, Mercado Livre): Hermes `/discover` browses the marketplace's own search page (`MarketplaceBrowserStrategy::searchUrl()`) for every search in one run, with `ingest: false` and `per_source_products` so each search contributes its share. Hermes only reports what it saw and sends nothing to PriceBuddy.
   - The star rating and sales count shown by the marketplace (Shopee API, or the listing Hermes read) are kept on the candidate.
   - A store that fails or finds little is recorded in `carousels.store_reports` and never stops the other stores.
3. **Eligibility**: before scoring, candidates are dropped when they have:
   - an invalid URL,
   - no title,
   - no price,
   - no image (when required),
   - the same normalized URL as another candidate,
   - a nearly identical title (when duplicate filtering is on).
4. **Editorial score** (`CarouselCurator`): the AI provider selected for *Carousel curation* (Settings > AI) scores each candidate from 0 to 100 on:
   - *not obvious* (`originality`): a shopper browsing the niche would not think of it; ordinary items such as generic chargers or phone holders score low even when they sell well,
   - usefulness,
   - *proven demand* (`commercial_potential`): raised by the sales count and rating when known,
   - niche relevance,
   - visual appeal.

   The weighted score uses the weights from Settings > Carousels.
5. **Suggested selection**: the best products above the minimum score are pre-selected (5 by default), one per kind of product first, so that a "Coffee Corner" carousel does not end up with five mugs. The kind of product is a broad 1–2 word category written by the AI provider.
6. **Add by URL**: while the carousel is in review, a product URL from a registered store can be added as a candidate. It is read through the store's usual path (API provider or scraper, with its proxy and strategies, as when a product is added to the catalog), gets the affiliate link from the store's API when there is one, and is scored like the others. It is marked "Added by URL". Nothing is added to the catalog until approval.
7. **Approval** (`CarouselApproval`): the user selects, removes, reorders or rejects candidates, then approves between the minimum and maximum number of products (3–10 by default). Approval is mandatory. It is also the only step that writes to the catalog: the 5 products are ingested with `IngestProductCandidateAction`, keeping their store, affiliate link and price, and the carousel can no longer change.

Candidates stay in `carousel_candidates` and never enter the catalog unless they are approved.

## What is verified and what is inferred

- **Verified**, from the store's API or the marketplace page: URL, title, image, price, original price and the affiliate link from the API.
- **Verified** too, when the marketplace shows them: star rating and sales count.
- **Inferred** by the AI provider: search terms, scores, reasons, product type and the short description. The model **only reads the title, store, price, rating and sales count**. The image is never sent, so *image quality* is not scored (its default weight is 0). The page says so next to every score.
- **Availability** is not verified at this stage and is shown as "Availability not verified".

## Settings > Carousels

| Field | Default |
|---|---|
| Region / language | BR / pt-BR |
| Suggested niches | 7 Brazilian Portuguese starting niches; each niche is also the marketplace search term |
| Show suggestions / allow custom niches | on / on |
| Stores | all stores that support carousel discovery |
| Target candidates per store | 8 (up to 3× that many are kept for scoring) |
| Minimum editorial score | 60 |
| Filter duplicates / require image | on / on |
| Weights (%) | not obvious 30, usefulness 25, proven demand 25, niche 15, visual 5, image 0 (must add up to 100) |
| Minimum / maximum / pre-selected products | 3 / 10 / 5 |
| Manual approval | always required |

## Profiles and the Canva AI prompt

PriceBuddy does not design the slides. After approval it writes one prompt to paste into Canva AI and offers the product photos it refers to; the result is adjusted in Canva.

- **Profiles** (Settings > Carousels > Profiles): one per Instagram profile (e.g. one for a male audience, another for a female audience). Each one has a name, a default call to action and a **style guide**: palette with hex codes, typography, mood, page layout, background and photo treatment, and what to avoid.
- **Generating the style guide:** write a short description and/or attach a reference image (a post or design whose look you want), then click **Generate with AI**. It uses the AI provider chosen for *Carousel style* (Settings > AI); a reference image needs a model that reads images (e.g. Gemini). The guide fills the field and can be edited freely before saving; when the description is empty, a short one written by the AI fills it too, so a reference image alone is enough. Reference images are stored in `carousels/profiles` on the public disk.
- **On an approved carousel**, the *Canva AI* section has the profile, the cover headline and the call to action (blank uses the profile's default; with neither, the prompt has no closing page), and:
  - **Generate headline and call to action**: the AI provider chosen for *Carousel curation* suggests 5 cover phrases from the niche and the selected products (click one to use it) and fills the call to action when it is blank.
  - **Copy prompt**: the profile's style guide, then one page per slide (cover, each product in order with its title and description, then the closing page). Prices, stores and links are never in the prompt: the carousel sparks curiosity so people ask for the links. The prompt is assembled as plain text, so every carousel of a profile carries the same style block.
  - **Download product photos**: a zip with the marketplace photos named `produto-01`, `produto-02`… in slide order, the names the prompt uses. A photo that cannot be downloaded is reported and left out.

## Pinterest Trends

Pinterest Trends is not used.

- The official endpoint (`GET /v5/trends/keywords/{region}/top/{trend_type}`) supports `BR`, but Pinterest describes it as limited to agencies, enterprise clients and partners.
- The developer guidelines forbid storing data read through the API.
- The terms of service forbid scraping trends.pinterest.com.

Niche suggestions therefore come only from the configurable list and the user's tags, and they are never presented as Pinterest trends.

## Operations

- The `carousels` queue has its own worker (`docker/php/supervisord.conf`). A Hermes search can take up to the Hermes run timeout per store, and this worker keeps it from delaying price updates.
- Hermes runs one discovery at a time. While a deals discovery is running, that store's carousel search fails with HTTP 409 and the other stores continue.
