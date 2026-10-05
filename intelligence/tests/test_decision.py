import unittest
from datetime import datetime, timedelta

from tests import helpers  # noqa: F401
from analysis import decision as d

NOW = datetime(2026, 10, 5, 14, 0)


def offer(price=100.0, original=150.0, history=None):
    return d.Offer(1, "Produto X 128GB", "Amazon", price, original, "https://loja/1",
                   history if history is not None else {"2026-09-20": 140.0, "2026-09-30": 135.0})


def ref(price, status="same", store="Magalu"):
    return d.Reference("google_shopping", store, "Produto X 128GB", price, None, None, status)


class DecisionTest(unittest.TestCase):
    def decide(self, o=None, refs=(), market="completed", last=None, now=NOW, **rules):
        return d.decide(o or offer(), list(refs), last_publication=last, market_status=market, now=now, rules=rules)

    def test_publish_now_when_cheapest_with_enough_references(self):
        result = self.decide(refs=[ref(105), ref(110, store="KaBuM!")])
        self.assertEqual(result["action"], d.PUBLISH_NOW)
        self.assertEqual(result["prices"]["references_confirmed"], 2)

    def test_schedule_outside_posting_hours(self):
        result = self.decide(refs=[ref(105), ref(110)], now=NOW.replace(hour=23))
        self.assertEqual(result["action"], d.SCHEDULE)
        self.assertTrue(result["scheduled_for"].startswith("2026-10-06T08:00"))

    def test_insufficient_references_never_confirms(self):
        self.assertEqual(self.decide(refs=[ref(105)])["action"], d.WAIT_CONFIRMATION)
        uncertain = [ref(105, "uncertain"), ref(106, "uncertain")]
        self.assertEqual(self.decide(refs=uncertain)["action"], d.WAIT_CONFIRMATION)

    def test_market_not_checked_never_confirms(self):
        result = self.decide(refs=[ref(105), ref(110)], market="blocked")
        self.assertEqual(result["action"], d.WAIT_CONFIRMATION)
        self.assertTrue(any("market reference unavailable" in r for r in result["risks"]))

    def test_cheaper_elsewhere_is_ignored_or_monitored(self):
        self.assertEqual(self.decide(refs=[ref(85), ref(110)])["action"], d.IGNORE)
        self.assertEqual(self.decide(refs=[ref(95), ref(110)])["action"], d.MONITOR)

    def test_small_gap_is_still_publishable(self):
        self.assertEqual(self.decide(refs=[ref(98), ref(110)])["action"], d.PUBLISH_NOW)

    def test_no_real_drop_is_monitored(self):
        flat = offer(price=100, original=None, history={"2026-09-20": 101.0, "2026-09-30": 99.0})
        self.assertEqual(self.decide(flat, refs=[ref(105), ref(110)])["action"], d.MONITOR)

    def test_recent_duplicate_is_ignored_and_bigger_drop_reposts(self):
        last = {"price": 100.0, "published_at": (NOW - timedelta(hours=3)).isoformat()}
        self.assertEqual(self.decide(refs=[ref(105), ref(110)], last=last)["action"], d.IGNORE)
        cheaper = offer(price=90)
        self.assertEqual(self.decide(cheaper, refs=[ref(105), ref(110)], last=last)["action"], d.REPOST)

    def test_coupon_is_a_condition_not_a_discount(self):
        o = offer(price=100, original=110, history={"2026-09-20": 100.0})
        o.conditions = ["coupon: Cupom 20%"]
        result = self.decide(o, refs=[ref(105), ref(110)])
        self.assertEqual(result["action"], d.MONITOR)
        self.assertIn("condition: coupon: Cupom 20%", result["risks"])

    def test_priority_puts_publishable_first(self):
        results = [self.decide(refs=[ref(85), ref(110)]), self.decide(refs=[ref(105), ref(110)])]
        self.assertEqual(sorted(results, key=d.priority)[0]["action"], d.PUBLISH_NOW)


if __name__ == "__main__":
    unittest.main()
