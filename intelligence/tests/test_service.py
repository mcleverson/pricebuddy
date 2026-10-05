import unittest

from tests.helpers import FakeHermes, FakePriceBuddy, FakeSender, product
from analysis import decision as d
from analysis.service import Analyzer
from publishing.service import PublicationError, Publisher
from store import Store

TITLE = "Smartphone Samsung Galaxy A36 5G 128GB 6GB RAM"
MARKET = [
    {"title": "Samsung Galaxy A36 5G 128GB 6GB RAM Violeta", "price": 1600.0, "store": "Magalu"},
    {"title": "Celular Samsung Galaxy A36 128GB 6GB RAM 5G", "price": 1650.0, "store": "Casas Bahia"},
    {"title": "Samsung Galaxy A37 256GB 5G", "price": 1400.0, "store": "KaBuM!"},
]
SETTINGS = {"posting_start_hour": 0, "posting_end_hour": 24, "market_search_delay_seconds": 0}


def deal(pid=1, price=1500.0, **kw):
    return product(pid, TITLE, price, original=2000.0, history={"2026-09-01": 1900.0}, **kw)


class ServiceTest(unittest.TestCase):
    def setUp(self):
        self.store = Store(":memory:")

    def analyzer(self, products, hermes=None, settings=None):
        return Analyzer(self.store, FakePriceBuddy(products, {**SETTINGS, **(settings or {})}), hermes or FakeHermes(MARKET),
                        sleep=lambda s: None)

    def test_batch_confirms_with_market_and_ignores_other_variants(self):
        analyzer = self.analyzer([deal()])
        summary = analyzer.run_batch()
        self.assertEqual(summary["actions"], {d.PUBLISH_NOW: 1})
        today = analyzer.today()['data'][0]
        statuses = {ref["store"]: ref["equivalence"] for ref in today["references"]}
        self.assertEqual(statuses["KaBuM!"], "different")  # cheaper, but an A37
        self.assertEqual(today["prices"]["references_confirmed"], 2)

    def test_market_is_searched_only_for_candidates(self):
        hermes = FakeHermes(MARKET)
        flat = product(2, "Fone Bluetooth Genérico XYZ", 100.0, original=None, history={"2026-09-01": 100.0})
        self.analyzer([flat], hermes).run_batch()
        self.assertEqual(hermes.queries, [])

    def test_search_budget_and_blocked_search_never_confirm(self):
        analyzer = self.analyzer([deal(1), deal(2, created=None)], FakeHermes(status="blocked"),
                                 {"market_searches_per_day": 1})
        analyzer.run_batch()
        actions = {a["product_id"]: (a["action"], a["market_status"]) for a in analyzer.today()['data']}
        self.assertEqual(sorted(actions.values()), [(d.WAIT_CONFIRMATION, "blocked"), (d.WAIT_CONFIRMATION, "budget_exhausted")])

    def test_offer_is_the_cheapest_store_of_the_product(self):
        analyzer = self.analyzer([deal(extra_stores=[("Mercado Livre", 1200.0)])])
        analyzer.run_batch()
        today = analyzer.today()['data'][0]
        self.assertEqual((today["store"], today["prices"]["offer"]), ("Mercado Livre", 1200.0))
        self.assertIn("Amazon.com.br", {ref["store"] for ref in today["references"]})

    def test_coupon_is_reported_as_condition(self):
        coupon = {"status": "active", "store_id": 21, "title": "Cupom 8%", "code": None}
        analyzer = self.analyzer([deal(coupons=[coupon])])
        analyzer.run_batch()
        self.assertIn("coupon: Cupom 8% (activate on the product page)", analyzer.today()['data'][0]["conditions"])


    def test_today_reports_products_still_waiting_for_analysis(self):
        analyzer = self.analyzer([deal(1), deal(2), deal(3)])
        analyzer.run_batch(limit=2)
        self.assertEqual(analyzer.today()["meta"]["pending"], 1)


class PublisherTest(unittest.TestCase):
    def setUp(self):
        self.store = Store(":memory:")
        self.products = [deal()]
        self.pricebuddy = FakePriceBuddy(self.products, {**SETTINGS, "telegram_enabled": True,
                                                         "telegram_bot_token": "x", "telegram_chat_id": "1"})
        self.analyzer = Analyzer(self.store, self.pricebuddy, FakeHermes(MARKET), sleep=lambda s: None)
        self.sender = FakeSender()
        self.publisher = Publisher(self.store, self.analyzer, self.sender)
        self.analyzer.run_batch()

    def test_dry_run_is_the_default(self):
        publication = self.publisher.create(1)
        self.assertEqual(publication["status"], "dry_run")
        self.assertTrue(self.sender.sent[0]["dry_run"])
        self.assertIn("R$ 1.500,00", self.sender.sent[0]["text"])

    def test_sends_only_with_explicit_configuration(self):
        self.pricebuddy.saved["dry_run"] = False
        self.assertEqual(self.publisher.create(1)["status"], "sent")
        self.assertFalse(self.sender.sent[0]["dry_run"])

    def test_duplicate_is_blocked(self):
        self.publisher.create(1)
        self.analyzer.analyze(self.pricebuddy.product(1), self.products, self.analyzer.settings())
        with self.assertRaises(PublicationError):
            self.publisher.create(1)

    def test_revalidation_cancels_when_price_went_up(self):
        publication = self.publisher.create(1, scheduled_for="2099-01-01T08:00:00-03:00")
        self.assertEqual(publication["status"], "scheduled")
        self.pricebuddy.items[1]["price_cache"][0]["price"] = 1700.0
        result = self.publisher.send(publication["id"])
        self.assertEqual(result["status"], "cancelled")
        self.assertIn("price went up", result["detail"])
        self.assertEqual(self.sender.sent, [])

    def test_publication_status_per_product(self):
        self.assertEqual(self.store.publication_statuses(), {})
        scheduled = self.publisher.create(1, scheduled_for="2099-01-01T08:00:00-03:00")
        self.assertEqual(self.store.publication_statuses()[1]["status"], "in_queue")
        self.publisher.cancel(scheduled["id"])
        self.publisher.create(1)
        self.assertEqual(self.store.publication_statuses()[1]["status"], "published_dry_run")

    def test_not_publishable_recommendation_is_refused(self):
        self.pricebuddy.items[3] = product(3, "Fone Genérico", 100.0, history={"2026-09-01": 100.0})
        self.analyzer.run_batch()
        with self.assertRaises(PublicationError):
            self.publisher.create(3)


if __name__ == "__main__":
    unittest.main()
