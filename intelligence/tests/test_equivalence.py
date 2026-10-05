import unittest

from tests import helpers  # noqa: F401  (path setup)
from analysis.equivalence import DIFFERENT, SAME, UNCERTAIN, compare

BUNDLE = "Bundle Nintendo Switch OLED + Super Mario Bros Wonder + 3 Meses de Assinatura Nintendo Switch Online"


class EquivalenceTest(unittest.TestCase):
    def test_same_bundle_written_differently(self):
        self.assertEqual(compare(BUNDLE, "Bundle Nintendo Switch Oled + Super Mario Bros. Wonder + 3Mo Nso").status, SAME)

    def test_variant_word_differs(self):
        verdict = compare(BUNDLE, "Bundle Nintendo Switch + Super Mario Bros. Wonder + 3 Meses de Assinatura")
        self.assertEqual(verdict.status, DIFFERENT)
        self.assertIn("variant", verdict.reasons[0])

    def test_subscription_months_differ(self):
        self.assertEqual(compare(BUNDLE, "Nintendo Switch Oled + Super Mario Bros. Wonder + 12 Meses Assinatura").status,
                         DIFFERENT)

    def test_model_and_storage_differ(self):
        self.assertEqual(compare("Samsung Galaxy A36 5G 128GB 6GB RAM", "Samsung Galaxy A37 256GB 5G").status, DIFFERENT)

    def test_bundled_extra_is_another_product(self):
        verdict = compare("Samsung Galaxy A36 5G 128GB 6GB RAM Preto",
                          "Samsung Galaxy A36 128Gb 5G 6Gb Ram - Preto + Galaxy Buds Fe Sem Fio")
        self.assertEqual(verdict.status, DIFFERENT)

    def test_color_does_not_change_variant(self):
        self.assertEqual(compare("Samsung Galaxy A36 5G 128GB 6GB RAM Preto",
                                 "Celular Samsung Galaxy A36 5g 128gb 6gb Ram Violeta").status, SAME)

    def test_missing_attribute_is_uncertain_not_same(self):
        self.assertEqual(compare("Samsung Galaxy A36 5G 128GB 6GB RAM", "Smartphone Samsung Galaxy A36 5G").status,
                         UNCERTAIN)

    def test_voltage_and_volume(self):
        self.assertEqual(compare("Air Fryer Mondial 4L 127V", "Air Fryer Mondial 4L 220V").status, DIFFERENT)
        self.assertEqual(compare("Perfume Club De Nuit Intense 105ml", "Perfume Club De Nuit Intense 200ml").status,
                         DIFFERENT)

    def test_kit_quantity(self):
        self.assertEqual(compare("Kit 3 Desodorante Rexona Clinical 48g", "Kit 2 Desodorante Rexona Clinical 48g").status,
                         DIFFERENT)

    def test_used_reference_is_not_the_new_offer(self):
        self.assertEqual(compare("iPhone 15 128GB Preto", "iPhone 15 128GB Preto Usado").status, DIFFERENT)


if __name__ == "__main__":
    unittest.main()
