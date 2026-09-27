# Tags

Tags are used to categorize your products. You can add multiple tags
to a product to help you organize them. 

Tags are unique to the user who has added them, so you will only see the tags
that you have added to your account.

## Adding a tag

You can add new tags via the tags page or when adding a product.

## Relevance profile (discovery)

A tag used as a store's discovery niche can carry a **Relevance profile**: the
rules for which discovered products belong to that niche. They apply to every
store using the niche, in both Agentic (Hermes) and Api (e.g. Shopee) discovery.
It is not a strict whitelist: new products semantically related to what is
described are also recognized.

| Field | Purpose |
| --- | --- |
| Additional instructions | Free text with the niche's intent, sent to the agent as-is. |
| Minimum / maximum price | Candidates outside the range are rejected. |
| Desired product types | e.g. smartphone, air fryer. |
| Priority brands | Preferred brands. Not an exclusive list: other recognized brands are also admitted. |
| Relevant products / families / models | e.g. Galaxy S, iPhone. |
| Synonyms and term variations | e.g. celular, telefone. |
| Allowed categories | Marketplace categories that fit the niche. |
| Excluded product types / brands | Never admitted. Use them for accessories and parts (e.g. case, cable, charger, spare part). |
| Excluded terms | Titles containing any of these whole words are rejected before the LLM is asked (e.g. refurbished, used). |
| Examples of desired / undesired products | Titles that illustrate what to admit or reject. |

For each new candidate, the deterministic rules (excluded terms and brands,
price range) run first. The LLM then only *describes* the candidate: its niche,
brand and a classification (`relevant`, `secondary`, `generic`, `accessory`,
`part`, `excluded`, `ambiguous` or `off_niche`), plus its brand tier and whether it
is a sample. Only `relevant` and `secondary` candidates are admitted, and the
store's **Brand requirement** decides which brands pass: by default, one of the
niche's **Priority brands** (matched in the title) or a nationally/internationally
recognized brand sold beyond marketplaces; **Any brand** also admits generic items;
**Only priority brands** admits nothing else. Samples/sachets are never admitted. The niche the evaluation picked wins, so a product
found under the wrong niche follows (and is tagged with) the right one. Each decision and its
reason is logged in the run report. Rejected candidates do not count towards a
store's minimum new products. This is admission only: scoring and ranking happen
later in PriceBuddy.

For Api stores that search by keyword (e.g. Shopee), the niche's **Desired
product types** and **Relevant products** are also the search terms: a few per
run, rotating across runs so every term is eventually searched. A niche without
a profile is searched by its name.

Only the profiles of a store's own niches are sent to the agent. When none of a
store's niches has a profile, discovery is not filtered and no extra LLM call is
made. For Api stores, products already in your catalog are only refreshed; if the
evaluation is unavailable, no new product is ingested.

## Filtering by tag

There is a tag filter on the product listing page, you can add one or more tags
to filter the products by.

## Tags on the dashboard

Products are grouped by tag on the dashboard. A product with no tag is grouped
under `Uncategorized`. Only your favourite, published products appear here.

The dashboard remembers how you arrange it — the ordering and collapse state
below are saved per user.

### Reorder tag groups

Drag a group by its heading — the tag icon and name — to move it up or down. The
order is remembered the next time you visit.

### Collapse groups

Use the chevron on the right of a group heading to collapse or expand that group.
Collapsed groups stay collapsed until you expand them again.

### Move products between groups

Drag a product by its image to:

- reorder it within its current group, or
- drop it onto another group to re-tag it. Dropping a product onto a tag group
  replaces its tags with that group's tag(s); dropping it onto `Uncategorized`
  removes its tags.

See [Customisable dashboard](/features.html#customisable-dashboard) for the
summary stats and smart sections that sit above your tag groups.
