"""Deterministic same-model/same-variant check between two product titles.

A reference only counts when it is the same model AND the same variant
(storage, volume, weight, voltage, screen size, quantity/kit, subscription
months, variant words such as OLED/Pro/Max, bundled extras). Anything that
cannot be confirmed from the titles is "uncertain", never "same".
"""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass, field

SAME = "same"
DIFFERENT = "different"
UNCERTAIN = "uncertain"

# Words that turn a product into another variant of the same line.
VARIANT_WORDS = {
    "oled", "lite", "pro", "max", "plus", "ultra", "mini", "air", "slim", "fe",
    "digital", "edge", "neo", "prime", "se", "xl", "xs", "turbo",
}
CONDITION_WORDS = {"usado", "usada", "recondicionado", "recondicionada", "seminovo", "seminova", "vitrine", "refurbished"}
STOPWORDS = {
    "de", "da", "do", "das", "dos", "e", "com", "para", "por", "em", "a", "o", "as", "os", "the", "and", "with",
    "novo", "nova", "original", "oficial", "lacrado", "lacrada", "envio", "imediato", "pronta", "entrega",
    "promocao", "oferta", "garantia", "nacional", "brasil", "br", "cor", "un", "unidade", "kit",
}
COLOR_WORDS = {
    "preto", "preta", "branco", "branca", "azul", "verde", "vermelho", "vermelha", "rosa", "cinza", "grafite",
    "prata", "dourado", "dourada", "roxo", "roxa", "violeta", "amarelo", "amarela", "lilas", "bege", "marrom",
    "black", "white", "blue", "green", "red", "pink", "gray", "grey", "silver", "gold", "purple",
}

UNIT_PATTERNS = {
    "storage": re.compile(r"(\d+(?:[.,]\d+)?)\s?(tb|gb)\b"),
    "ram": re.compile(r"(\d+)\s?gb\s?(?:de\s)?ram\b|\bram\s?(?:de\s)?(\d+)\s?gb"),
    "volume": re.compile(r"(\d+(?:[.,]\d+)?)\s?(ml|l|litros?)\b"),
    "weight": re.compile(r"(\d+(?:[.,]\d+)?)\s?(kg|g|gramas?)\b"),
    "screen": re.compile(r"(\d{2}(?:[.,]\d)?)\s?(?:\"|''|pol\b|polegadas)"),
    "months": re.compile(r"(\d+)\s?(?:meses|mes|months?|mo)\b"),
}
VOLTAGE = re.compile(r"\b(110|127|220)\s?v\b|\bbivolt\b")
QUANTITY = re.compile(r"\b(?:kit|pack|combo|leve)\s?(?:com\s)?(\d+)\b|\b(\d+)\s?(?:unidades|unid|pecas|pcs|pares|x)\b")
MODEL_CODE = re.compile(r"\b(?=[a-z0-9-]*\d)(?=[a-z0-9-]*[a-z])[a-z0-9-]{2,}\b")


def normalize(text: str) -> str:
    text = unicodedata.normalize("NFKD", text or "").encode("ascii", "ignore").decode().lower()
    text = text.replace("”", '"').replace("“", '"')
    return " ".join(text.split())


def _number(value: str) -> float:
    return float(value.replace(",", "."))


@dataclass
class Attributes:
    units: dict[str, set] = field(default_factory=dict)
    voltage: set[str] = field(default_factory=set)
    quantity: set[int] = field(default_factory=set)
    models: set[str] = field(default_factory=set)
    variants: set[str] = field(default_factory=set)
    conditions: set[str] = field(default_factory=set)
    extras: set[str] = field(default_factory=set)
    words: set[str] = field(default_factory=set)


def extract(title: str) -> Attributes:
    text = normalize(title)
    main, _, extra = text.partition("+")
    attrs = Attributes()
    for name, pattern in UNIT_PATTERNS.items():
        values = set()
        for match in pattern.finditer(text):
            groups = [g for g in match.groups() if g]
            if not groups:
                continue
            if name == "storage":
                if re.search(r"ram", text[match.end():match.end() + 6]):
                    continue  # "8gb ram" is memory, not storage
                amount = _number(groups[0]) * (1024 if groups[1] == "tb" else 1)
                values.add(amount)
            elif name in ("volume", "weight"):
                unit = groups[1]
                factor = 1000 if unit in ("l", "litro", "litros", "kg") else 1
                values.add(_number(groups[0]) * factor)
            else:
                values.add(_number(groups[0]))
        if values:
            attrs.units[name] = values
    attrs.voltage = {m.group(1) or "bivolt" for m in VOLTAGE.finditer(text)}
    attrs.quantity = {int(g) for m in QUANTITY.finditer(text) for g in m.groups() if g}
    unit_tokens = {m.group(0).replace(" ", "") for p in UNIT_PATTERNS.values() for m in p.finditer(text)}
    words = set(re.findall(r"[a-z0-9-]+", main))
    attrs.models = {w for w in MODEL_CODE.findall(main) if w not in unit_tokens and not re.fullmatch(r"\d+(gb|tb|ml|g|kg|v|w|mp|mah|hz|x)", w)
                    and w not in {"5g", "4g", "3g", "wi-fi", "wifi"}}
    attrs.variants = words & VARIANT_WORDS
    attrs.conditions = set(re.findall(r"[a-z]+", text)) & CONDITION_WORDS
    attrs.words = {w for w in words if len(w) > 2 and w not in STOPWORDS and w not in COLOR_WORDS and not w.isdigit()}
    extra_words = set(re.findall(r"[a-z]+", extra)) - STOPWORDS - COLOR_WORDS
    attrs.extras = {w for w in extra_words if len(w) > 3 and w not in {"meses", "assinatura", "individual", "online"}}
    return attrs


@dataclass
class Verdict:
    status: str
    reasons: list[str]
    similarity: float


def compare(offer_title: str, reference_title: str) -> Verdict:
    """Is the reference the same model and variant as the offer?"""
    a, b = extract(offer_title), extract(reference_title)
    reasons: list[str] = []
    uncertain: list[str] = []

    for name in sorted(set(a.units) | set(b.units)):
        if name in a.units and name in b.units:
            if not a.units[name] & b.units[name]:
                reasons.append(f"{name} differs ({_fmt(a.units[name])} vs {_fmt(b.units[name])})")
        elif name in a.units:
            uncertain.append(f"{name} not stated in reference")
    if a.voltage and b.voltage and not (a.voltage & b.voltage) and "bivolt" not in a.voltage | b.voltage:
        reasons.append(f"voltage differs ({'/'.join(sorted(a.voltage))} vs {'/'.join(sorted(b.voltage))})")
    if (a.quantity or b.quantity) and a.quantity != b.quantity:
        if a.quantity and b.quantity:
            reasons.append(f"quantity differs ({_fmt(a.quantity)} vs {_fmt(b.quantity)})")
        else:
            uncertain.append("quantity/kit stated in only one title")
    if a.variants != b.variants:
        reasons.append(f"variant differs ({_fmt(a.variants) or '-'} vs {_fmt(b.variants) or '-'})")
    if b.conditions - a.conditions:
        reasons.append(f"reference is {_fmt(b.conditions - a.conditions)}")
    missing_models = a.models - b.models - _joined(b)
    extra_models = b.models - a.models - _joined(a)
    if missing_models and extra_models:
        reasons.append(f"model differs ({_fmt(missing_models)} vs {_fmt(extra_models)})")
    elif missing_models:
        uncertain.append(f"model code {_fmt(missing_models)} not in reference")
    if b.extras - a.words - a.extras:
        reasons.append(f"reference bundles extras ({_fmt(b.extras - a.words - a.extras)})")

    union = a.words | b.words
    similarity = len(a.words & b.words) / len(union) if union else 0.0
    if reasons:
        return Verdict(DIFFERENT, reasons, similarity)
    if similarity < 0.35:
        return Verdict(DIFFERENT, [f"titles too different (similarity {similarity:.2f})"], similarity)
    if uncertain or similarity < 0.5:
        return Verdict(UNCERTAIN, uncertain or [f"low title similarity ({similarity:.2f})"], similarity)
    return Verdict(SAME, ["model and variant attributes match"], similarity)


def _joined(attrs: Attributes) -> set[str]:
    """Model codes written with a space in one title ("A 36") are joined in the other ("a36")."""
    words = sorted(attrs.words)
    return {x + y for x in words for y in words if x != y}


def _fmt(values) -> str:
    return ", ".join(str(int(v)) if isinstance(v, float) and v.is_integer() else str(v) for v in sorted(values, key=str))
