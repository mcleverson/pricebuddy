# Tags

Tags are used to categorize your products. You can add multiple tags
to a product to help you organize them. 

Tags are unique to the user who has added them, so you will only see the tags
that you have added to your account.

## Adding a tag

You can add new tags via the tags page or when adding a product.

## Relevance profile (discovery)

A tag used as a store's discovery niche can carry a **Relevance profile**, which
tells the Hermes agent which products truly belong to that niche. It is not a
strict whitelist: the agent also admits new products semantically related to
what is described.

| Field | Purpose |
| --- | --- |
| Desired product types | e.g. smartphone, air fryer. |
| Priority brands | Preferred brands. Other brands are still accepted when relevant. |
| Relevant products / families / models | e.g. Galaxy S, iPhone. |
| Synonyms and term variations | e.g. celular, telefone. |
| Allowed categories | Marketplace categories that fit the niche. |
| Excluded product types / brands | Never admitted. |
| Excluded terms | Titles containing any of these whole words are rejected before the LLM is asked. |
| Examples of desired / undesired products | Titles that illustrate what to admit or reject. |

Only the profiles of a store's own niches are sent to the agent. When neither
the store nor any of its niches has a profile, discovery is not filtered. See
[Stores → Relevance](./stores.md#relevance) for the strategy-level rules.

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
