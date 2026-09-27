"""Relevance admission: profile rules, LLM decision contract, cache and discovery integration."""
import sys
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1] / 'src'))
from agent import Agent
from browser_tools import BrowserToolSet, BrowserToolResult, ProductCandidate
from llm_client import LLMClientError, ToolCall
from relevance import DecisionCache, RelevanceEvaluator, RelevanceProfile, parse_decisions

SMARTPHONE_PROFILE = {
    'strategy': {
        'instructions': 'Buscar smartphones, priorizando Samsung e Apple.',
        'exclude_kinds': ['accessories', 'bogus'],
        'condition': 'new',
        'max_price': 1500,
        'exclude_terms': ['película'],
    },
    'niches': {
        'Celulares': {'include_product_types': ['smartphone', ' '], 'exclude_terms': ['capa']},
        'Other niche not configured': {'include_product_types': ['tv']},
    },
}


def candidate(title, tag=None, price='100.00', original_price='200.00'):
    return ProductCandidate(url='https://example.com/' + title.replace(' ', '-'), title=title,
                            price=price, original_price=original_price, tag=tag)


def decision(index, classification='relevant', ingest=True, confidence=0.9, **extra):
    return {'index': index, 'ingest': ingest, 'classification': classification,
            'confidence': confidence, 'reason': 'test', **extra}


class RelevanceProfileTest(unittest.TestCase):
    def test_profile_keeps_only_known_values_and_configured_niches(self):
        profile = RelevanceProfile(SMARTPHONE_PROFILE, ['Celulares'])

        self.assertTrue(profile.enabled)
        self.assertEqual(profile.strategy['exclude_kinds'], ['accessories'])
        self.assertEqual(profile.niches, {'Celulares': {'include_product_types': ['smartphone'],
                                                        'exclude_terms': ['capa']}})

    def test_empty_or_invalid_profile_is_disabled(self):
        for raw in (None, [], {}, {'strategy': {}, 'niches': {'Celulares': {'include_brands': []}}}):
            self.assertFalse(RelevanceProfile(raw, ['Celulares']).enabled)

    def test_exclude_terms_match_whole_words_only(self):
        profile = RelevanceProfile(SMARTPHONE_PROFILE, ['Celulares'])

        rejected = profile.rule_decision('Capa de silicone para Galaxy S24', 'Celulares')
        kept = profile.rule_decision('Smartphone 256GB de capacidade', 'Celulares')

        self.assertEqual(rejected.classification, 'excluded')
        self.assertFalse(rejected.ingest)
        self.assertEqual(rejected.source, 'rule')
        self.assertIsNone(kept)

    def test_niche_exclusion_does_not_reject_a_product_of_another_niche(self):
        profile = RelevanceProfile(
            {'niches': {'Celulares': {'exclude_terms': ['capa']}}}, ['Celulares', 'Acessórios'])

        self.assertIsNone(profile.rule_decision('Capa para iPhone', 'Acessórios'))
        self.assertIsNone(profile.rule_decision('Capa para iPhone', None))
        self.assertIsNotNone(profile.rule_decision('Capa para iPhone', 'Celulares'))

    def test_new_only_condition_rejects_refurbished(self):
        profile = RelevanceProfile(SMARTPHONE_PROFILE, ['Celulares'])

        self.assertEqual(profile.rule_decision('iPhone 13 Recondicionado', None).excluded_reason,
                         'condition: recondicionado')

    def test_price_constraints(self):
        profile = RelevanceProfile({'strategy': {'min_price': 100, 'max_price': 1500}}, [])

        self.assertIsNotNone(profile.price_rejection(1600))
        self.assertIsNotNone(profile.price_rejection(50))
        self.assertIsNone(profile.price_rejection(999))
        self.assertIsNone(profile.price_rejection(None))


class ParseDecisionsTest(unittest.TestCase):
    def test_invalid_entries_are_dropped(self):
        decisions = parse_decisions({'decisions': [
            decision(0),
            decision(0),  # duplicate index
            decision(5),  # out of range
            decision(1, classification='great'),
            {**decision(2), 'confidence': 1.5},
            {**decision(3), 'ingest': 'yes'},
        ]}, count=4, tags=['Celulares'], min_confidence=0.5)

        self.assertEqual(list(decisions), [0])

    def test_admission_requires_admitted_class_and_confidence(self):
        decisions = parse_decisions({'decisions': [
            decision(0, classification='accessory', ingest=True),
            decision(1, classification='secondary', ingest=True, confidence=0.3),
            decision(2, classification='secondary', ingest=True, niche='Unknown'),
            decision(3, classification='relevant', ingest=True, niche='Celulares', matched_brand='Samsung'),
        ]}, count=4, tags=['Celulares'], min_confidence=0.5)

        self.assertFalse(decisions[0].ingest)
        self.assertFalse(decisions[1].ingest)
        self.assertTrue(decisions[2].ingest)
        self.assertIsNone(decisions[2].niche)
        self.assertTrue(decisions[3].ingest)
        self.assertEqual(decisions[3].niche, 'Celulares')
        self.assertEqual(decisions[3].matched_brand, 'Samsung')


class RelevanceEvaluatorTest(unittest.TestCase):
    def evaluator(self, llm, cache=None):
        profile = RelevanceProfile(SMARTPHONE_PROFILE, ['Celulares'])
        return RelevanceEvaluator(llm, profile, cache=cache or DecisionCache())

    def test_rules_skip_the_llm_and_llm_decides_the_rest_in_one_batch(self):
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [
            decision(0), decision(1, classification='accessory', ingest=False),
        ]})
        items = [('k0', candidate('Película de vidro')), ('k1', candidate('Galaxy A15')),
                 ('k2', candidate('Carregador turbo'))]

        result = self.evaluator(llm).evaluate(items, timeout_seconds=30)

        self.assertEqual([d.source for d in result], ['rule', 'llm', 'llm'])
        self.assertEqual([d.ingest for d in result], [False, True, False])
        llm.chat_completion.assert_called_once()
        payload = llm.chat_completion.call_args.args[0][1]['content']
        self.assertIn('Galaxy A15', payload)
        self.assertNotIn('Película de vidro', payload)
        self.assertNotIn('Other niche not configured', payload)

    def test_incomplete_response_is_retried_then_missing_items_fail_closed(self):
        llm = Mock()
        llm.chat_completion.side_effect = [
            ToolCall('evaluate_candidates', {'decisions': [decision(0)]}),
            ToolCall('evaluate_candidates', {'decisions': [decision(0)]}),
        ]
        items = [('k0', candidate('Galaxy A15')), ('k1', candidate('Moto G84'))]

        result = self.evaluator(llm).evaluate(items, timeout_seconds=30)

        self.assertEqual(llm.chat_completion.call_count, 2)
        self.assertTrue(result[0].ingest)
        self.assertFalse(result[1].ingest)
        self.assertEqual(result[1].source, 'error')

    def test_llm_failure_fails_closed(self):
        llm = Mock()
        llm.chat_completion.side_effect = LLMClientError('down')

        result = self.evaluator(llm).evaluate([('k0', candidate('Galaxy A15'))], timeout_seconds=30)

        self.assertFalse(result[0].ingest)
        self.assertEqual(result[0].source, 'error')

    def test_repeated_candidates_use_the_cache(self):
        cache = DecisionCache()
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [decision(0)]})
        items = [('k0', candidate('Galaxy A15'))]

        self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)
        second = self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)

        llm.chat_completion.assert_called_once()
        self.assertEqual(second[0].source, 'cache')
        self.assertTrue(second[0].ingest)

    def test_error_decisions_are_not_cached(self):
        cache = DecisionCache()
        llm = Mock()
        llm.chat_completion.side_effect = [LLMClientError('down'),
                                           ToolCall('evaluate_candidates', {'decisions': [decision(0)]})]
        items = [('k0', candidate('Galaxy A15'))]

        self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)
        second = self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)

        self.assertEqual(second[0].source, 'llm')


class RelevanceDiscoveryFlowTest(unittest.TestCase):
    def run_flow(self, items, llm_responses, relevance_profile, tags=None, min_products=1):
        llm = Mock()
        llm.chat_completion.side_effect = llm_responses
        agent = Agent('example', 'smartphones', llm_client=llm, min_products=min_products,
                      starting_urls=['https://example.com/start'], allowed_hosts=['example.com'],
                      tags=tags or ['Celulares'], relevance_profile=relevance_profile,
                      min_discount_percentage=0)
        page_snapshot = {
            'url': 'https://example.com/start',
            'text': '\n'.join(item['title'] for item in items),
            'links': [{'text': item['title'], 'url': item['url']} for item in items],
            'buttons': [],
        }
        sent_urls = []

        def send(candidates, *args, **kwargs):
            sent_urls.append(candidates[0].url)
            return {'success': 1, 'existing': 0, 'failed': 0, 'created_urls': [candidates[0].url]}

        with ExitStack() as stack:
            stack.enter_context(patch.object(agent, '_launch_browser', return_value=(Mock(), Mock(), Mock())))
            stack.enter_context(patch('agent.setup_signal_handlers'))
            stack.enter_context(patch('agent.check_candidates_in_pricebuddy', side_effect=lambda urls, **kw: [
                {'url': url, 'key': url, 'exists': False, 'has_image': False} for url in urls]))
            stack.enter_context(patch('agent.send_candidates_to_pricebuddy', side_effect=send))
            stack.enter_context(patch.object(BrowserToolSet, '_tool_navigate', return_value=BrowserToolResult(True, 'ok')))
            stack.enter_context(patch.object(BrowserToolSet, '_tool_inspect_page',
                                             return_value=BrowserToolResult(True, 'ok', page_snapshot)))
            stack.enter_context(patch.object(BrowserToolSet, 'advance_listing',
                                             return_value=BrowserToolResult(True, 'No more pages', {'state': 'exhausted'})))
            enriched = stack.enter_context(patch.object(BrowserToolSet, '_tool_get_product_metadata',
                                                        return_value=BrowserToolResult(True, 'ok', {})))
            report = agent.run()
        return report, sent_urls, enriched, llm

    @staticmethod
    def item(name):
        return {'url': f'https://example.com/{name}', 'title': name, 'price': '100.00'}

    def test_only_admitted_candidates_are_enriched_and_ingested(self):
        phone, case, cable = self.item('Galaxy A15'), self.item('Capa Galaxy'), self.item('Cabo USB-C')
        report, sent_urls, enriched, llm = self.run_flow(
            [phone, case, cable],
            [ToolCall('collect_page', {'candidates': [phone, case, cable]}),
             ToolCall('evaluate_candidates', {'decisions': [
                 decision(0, niche='Celulares', normalized_product_type='smartphone'),
                 decision(1, classification='accessory', ingest=False),
             ]})],
            {'niches': {'Celulares': {'include_product_types': ['smartphone'], 'exclude_terms': ['capa']}}},
        )

        self.assertEqual(sent_urls, [phone['url']])
        self.assertEqual(enriched.call_count, 1)
        self.assertEqual(report['relevance']['enabled'], True)
        self.assertEqual(report['relevance']['decisions_by_classification'], {'excluded': 1, 'relevant': 1, 'accessory': 1})
        self.assertEqual(report['candidates'][0]['relevance']['normalized_product_type'], 'smartphone')
        relevance_steps = [step for step in report['steps'] if step['action'] == 'relevance']
        self.assertEqual(len(relevance_steps), 3)
        self.assertEqual({step['source'] for step in relevance_steps}, {'rule', 'llm'})

    def test_llm_niche_is_used_when_listing_did_not_pick_one(self):
        phone = self.item('Galaxy A15')
        report, _, _, _ = self.run_flow(
            [phone],
            [ToolCall('collect_page', {'candidates': [phone]}),
             ToolCall('evaluate_candidates', {'decisions': [decision(0, niche='Celulares')]})],
            {'niches': {'Celulares': {'include_product_types': ['smartphone']}}},
            tags=['Celulares', 'Casa'],
        )

        self.assertEqual(report['candidates'][0]['relevance']['niche'], 'Celulares')

    def test_without_profile_the_flow_is_unchanged(self):
        phone = self.item('Galaxy A15')
        report, sent_urls, _, llm = self.run_flow(
            [phone], [ToolCall('collect_page', {'candidates': [phone]})], None)

        self.assertEqual(sent_urls, [phone['url']])
        self.assertEqual(llm.chat_completion.call_count, 1)
        self.assertEqual(report['relevance'], {'enabled': False, 'decisions_by_classification': {}})

    def test_strategy_price_limit_is_a_deterministic_filter(self):
        cheap, expensive = self.item('Moto G24'), {**self.item('Galaxy S24'), 'price': '5000.00'}
        _, sent_urls, _, llm = self.run_flow(
            [cheap, expensive],
            [ToolCall('collect_page', {'candidates': [cheap, expensive]}),
             ToolCall('evaluate_candidates', {'decisions': [decision(0)]})],
            {'strategy': {'max_price': 1500}},
        )

        self.assertEqual(sent_urls, [cheap['url']])
        payload = llm.chat_completion.call_args_list[1].args[0][1]['content']
        self.assertNotIn('Galaxy S24', payload)


if __name__ == '__main__':
    unittest.main()
