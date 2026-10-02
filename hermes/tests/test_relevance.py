"""Relevance admission: niche rules, LLM description contract, code-side admission, cache and integration."""
import sys
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1] / 'src'))
from agent import Agent
from browser_tools import BrowserToolSet, BrowserToolResult, ProductCandidate
from llm_client import LLMClientError, ToolCall
from main import evaluate_request
from relevance import (DECISION_CACHE, DecisionCache, RelevanceDecision, RelevanceEvaluator,
                       RelevanceProfile, parse_decisions)

PHONES = {
    'instructions': 'Smartphones, priorizando Samsung e Apple.',
    'include_product_types': ['smartphone', ' '],
    'include_brands': ['Samsung', 'Apple'],
    'exclude_terms': ['capa', 'película'],
    'max_price': 1500,
    'brand_policy': 'removed option',
}


def profile(niches=None, tags=('Celulares',)):
    return RelevanceProfile({'niches': niches if niches is not None else {'Celulares': PHONES}}, list(tags))


def candidate(title, tag=None, price='100.00', original_price='200.00'):
    return ProductCandidate(url='https://example.com/' + title.replace(' ', '-'), title=title,
                            price=price, original_price=original_price, tag=tag)


def described(index, classification='relevant', confidence=0.9, **extra):
    return {'index': index, 'classification': classification, 'confidence': confidence, 'reason': 'test',
            'brand_tier': 'recognized', 'sample': False, 'authenticity': 'genuine', **extra}


def description(classification='relevant', confidence=0.9, **extra):
    return RelevanceDecision(ingest=False, classification=classification, confidence=confidence,
                             reason='test', **{'brand_tier': 'recognized', 'sample': False,
                                               'authenticity': 'genuine', **extra})


class RelevanceProfileTest(unittest.TestCase):
    def test_profile_keeps_only_known_values_and_configured_niches(self):
        p = profile({'Celulares': PHONES, 'Not configured here': {'include_product_types': ['tv']}})

        self.assertTrue(p.enabled)
        self.assertEqual(list(p.niches), ['Celulares'])
        self.assertEqual(p.niches['Celulares']['include_product_types'], ['smartphone'])
        self.assertEqual(p.niches['Celulares']['max_price'], 1500)
        self.assertNotIn('brand_policy', p.niches['Celulares'])

    def test_legacy_strategy_section_is_ignored(self):
        self.assertFalse(RelevanceProfile({'strategy': {'max_price': 10}}, ['Celulares']).enabled)

    def test_empty_or_invalid_profile_is_disabled(self):
        for raw in (None, [], {}, {'niches': {'Celulares': {'include_brands': [], 'condition': 'new'}}}):
            self.assertFalse(RelevanceProfile(raw, ['Celulares']).enabled)

    def test_exclude_terms_match_whole_words_only(self):
        p = profile()

        self.assertEqual(p.rule_decision('Capa de silicone para Galaxy S24', 'Celulares').classification, 'excluded')
        self.assertIsNone(p.rule_decision('Smartphone 256GB de capacidade', 'Celulares'))

    def test_niche_rules_do_not_leak_to_other_niches(self):
        p = profile({'Celulares': {'exclude_terms': ['capa']}}, tags=('Celulares', 'Acessórios'))

        self.assertIsNone(p.rule_decision('Capa para iPhone', 'Acessórios'))
        self.assertIsNone(p.rule_decision('Capa para iPhone', None))
        self.assertIsNotNone(p.rule_decision('Capa para iPhone', 'Celulares'))

    def test_single_niche_rules_apply_without_a_listing_niche(self):
        self.assertIsNotNone(profile().rule_decision('Capa para iPhone', None))

    def test_price_is_a_niche_rule(self):
        p = profile()

        self.assertEqual(p.rule_decision('Galaxy S24', 'Celulares', price=5000).excluded_reason, 'price range')
        self.assertIsNone(p.rule_decision('Galaxy A15', 'Celulares', price=999))
        self.assertIsNone(p.price_rejection(5000, 'Other'))


class AdmissionTest(unittest.TestCase):
    def test_only_relevant_and_secondary_with_enough_confidence_are_admitted(self):
        p = profile()
        for classification in ('relevant', 'secondary'):
            self.assertTrue(p.admit(description(classification, niche='Celulares'), 'Galaxy A15', 0.5).ingest)
        for classification in ('generic', 'accessory', 'part', 'excluded', 'off_niche', 'ambiguous'):
            decision = p.admit(description(classification, niche='Celulares'), 'Galaxy A15', 0.5)
            self.assertFalse(decision.ingest)
            self.assertEqual(decision.excluded_reason, classification)
        self.assertFalse(p.admit(description('relevant', confidence=0.3), 'Galaxy A15', 0.5).ingest)

    def test_unbranded_and_unknown_brands_are_never_admitted(self):
        p = profile()
        for tier in ('unknown', 'none', None):
            decision = p.admit(description('relevant', niche='Celulares', brand_tier=tier), 'Kit Extensão de Cílios', 0.5)
            self.assertFalse(decision.ingest)
            self.assertEqual(decision.excluded_reason, f"brand: {tier or 'unknown'}")
        self.assertTrue(p.admit(description('secondary', niche='Celulares', brand_tier='priority'), 'x', 0.5).ingest)

    def test_the_priority_tier_comes_from_the_maker_not_from_a_brand_cited_in_the_title(self):
        made_by = profile().admit(description('relevant', niche='Celulares', brand_tier='none', matched_brand='Samsung'),
                                  'Smartphone Samsung Galaxy A15', 0.5)
        cited = profile().admit(description('relevant', niche='Celulares', brand_tier='none', matched_brand='Kaidi'),
                                'Capinha estilo Samsung Galaxy', 0.5)

        self.assertEqual((made_by.ingest, made_by.brand_tier), (True, 'priority'))
        self.assertEqual((cited.ingest, cited.brand_tier), (False, 'none'))

    def test_brands_match_regardless_of_accents_apostrophes_and_spacing(self):
        p = profile({'Beleza': {'include_brands': ['Ruby Rose', "L'Oréal"]}}, tags=('Beleza',))

        for maker in ('Rubyrose', 'Melu by Ruby Rose', 'Ruby-Rose', 'L’Oreal Paris'):
            decision = p.admit(description('relevant', niche='Beleza', brand_tier='unknown', matched_brand=maker), 'x', 0.5)
            self.assertEqual(decision.brand_tier, 'priority', maker)
        self.assertEqual(p.admit(description('relevant', niche='Beleza', brand_tier='unknown', matched_brand='Rosebud'),
                                 'x', 0.5).brand_tier, 'unknown')

    def test_imitations_and_third_party_compatibles_are_never_admitted(self):
        for policy in ('any', 'recognized', 'priority'):
            p = RelevanceProfile({'niches': {'Games': {'include_brands': ['Nintendo']}}, 'brand_policy': policy}, ['Games'])
            for authenticity in ('imitation', 'third_party_compatible'):
                decision = p.admit(description('relevant', niche='Games', matched_brand='Nintendo',
                                               authenticity=authenticity), 'Mini Vídeo Game Nintendo 400 Jogos', 0.5)
                self.assertFalse(decision.ingest)
                self.assertEqual(decision.excluded_reason, f'authenticity: {authenticity}')

    def test_unclear_authenticity_is_only_rejected_under_the_priority_policy(self):
        def admit(policy):
            p = RelevanceProfile({'niches': {'Celulares': {'include_brands': ['Samsung']}}, 'brand_policy': policy},
                                 ['Celulares'])
            return p.admit(description('relevant', niche='Celulares', matched_brand='Samsung', authenticity='unclear'),
                           'Galaxy A15', 0.5)

        self.assertTrue(admit('recognized').ingest)
        self.assertFalse(admit('priority').ingest)

    def test_samples_are_never_admitted(self):
        decision = profile().admit(description('relevant', niche='Celulares', sample=True), 'Kit de Sachês', 0.5)

        self.assertFalse(decision.ingest)
        self.assertEqual(decision.excluded_reason, 'sample size')


class BrandPolicyTest(unittest.TestCase):
    NICHES = {'Celulares': {'include_brands': ['Samsung', 'Apple']}}

    def admit(self, policy, title, **decision):
        p = RelevanceProfile({'niches': self.NICHES, 'brand_policy': policy}, ['Celulares'])
        return p.admit(description(niche='Celulares', **decision), title, 0.5)

    def test_recognized_is_the_default_policy(self):
        self.assertEqual(RelevanceProfile({'niches': self.NICHES}, ['Celulares']).brand_policy, 'recognized')
        self.assertEqual(RelevanceProfile({'niches': self.NICHES, 'brand_policy': 'bogus'}, ['Celulares']).brand_policy,
                         'recognized')

    def test_any_brand_admits_generic_and_unbranded_items(self):
        self.assertTrue(self.admit('any', 'Fone TWS', classification='generic', brand_tier='none').ingest)
        self.assertTrue(self.admit('any', 'Fone Kaidi', classification='relevant', brand_tier='unknown').ingest)
        self.assertFalse(self.admit('any', 'Cabo USB', classification='accessory', brand_tier='none').ingest)
        self.assertFalse(self.admit('any', 'Sachê', brand_tier='recognized', sample=True).ingest)

    def test_recognized_rejects_generic_unknown_and_unbranded(self):
        self.assertFalse(self.admit('recognized', 'Fone TWS', classification='generic', brand_tier='none').ingest)
        self.assertFalse(self.admit('recognized', 'Fone Kaidi', brand_tier='unknown').ingest)
        self.assertTrue(self.admit('recognized', 'Smartphone Motorola', brand_tier='recognized').ingest)

    def test_priority_only_admits_brands_matched_by_the_code(self):
        self.assertTrue(self.admit('priority', 'Smartphone Samsung Galaxy A15', matched_brand='Samsung',
                                   brand_tier='none').ingest)
        self.assertFalse(self.admit('priority', 'Smartphone Samsung Galaxy A15', brand_tier='none').ingest)
        self.assertTrue(self.admit('priority', 'iPhone 15', matched_brand='Apple', brand_tier='recognized').ingest)
        rejected = self.admit('priority', 'Smartphone Motorola G84', matched_brand='Motorola', brand_tier='priority')
        self.assertFalse(rejected.ingest)
        self.assertEqual(rejected.excluded_reason, 'brand: not a priority brand')


class ParseDecisionsTest(unittest.TestCase):
    def test_invalid_entries_are_dropped_and_admission_is_not_decided_by_the_llm(self):
        decisions = parse_decisions({'decisions': [
            {**described(0, niche='Celulares'), 'ingest': True},
            described(0),  # duplicate index
            described(5),  # out of range
            described(1, classification='great'),
            {**described(2), 'confidence': 1.5},
            described(3, niche='Unknown'),
            {**described(1), 'brand_tier': 'famous'},
            {**described(2), 'sample': 'no'},
            {**described(2), 'authenticity': 'fake'},
        ]}, count=4, tags=['Celulares'])

        self.assertEqual(sorted(decisions), [0, 3])
        self.assertEqual((decisions[0].brand_tier, decisions[0].sample), ('recognized', False))
        self.assertFalse(decisions[0].ingest)
        self.assertEqual(decisions[0].niche, 'Celulares')
        self.assertIsNone(decisions[3].niche)

    def test_product_key_is_normalized(self):
        decisions = parse_decisions({'decisions': [
            described(0, product_key='  Ruby Rose|Melu|Máscara  de Cílios '),
            described(1, product_key=' '),
        ]}, count=2, tags=['Celulares'])

        self.assertEqual(decisions[0].product_key, 'ruby rose|melu|mascara de cilios')
        self.assertIsNone(decisions[1].product_key)


class RelevanceEvaluatorTest(unittest.TestCase):
    def evaluator(self, llm, cache=None, niches=None, tags=('Celulares',)):
        return RelevanceEvaluator(llm, profile(niches, tags), cache=cache or DecisionCache())

    def test_rules_skip_the_llm_and_the_code_admits_the_rest(self):
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [
            described(0, niche='Celulares', matched_brand='Samsung'),
            described(1, classification='accessory', niche='Celulares'),
        ]})
        items = [('k0', candidate('Película de vidro')), ('k1', candidate('Galaxy A15')),
                 ('k2', candidate('Carregador turbo'))]

        result = self.evaluator(llm).evaluate(items, timeout_seconds=30)

        self.assertEqual([d.source for d in result], ['rule', 'llm', 'llm'])
        self.assertEqual([d.ingest for d in result], [False, True, False])
        self.assertEqual(result[2].excluded_reason, 'accessory')
        llm.chat_completion.assert_called_once()
        payload = llm.chat_completion.call_args.args[0][1]['content']
        self.assertIn('Galaxy A15', payload)
        self.assertNotIn('Película de vidro', payload)
        self.assertNotIn('"ingest"', str(llm.chat_completion.call_args.args[1]))

    def test_rules_of_the_evaluated_niche_apply_after_the_llm_picks_it(self):
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [
            described(0, niche='Beleza'),
        ]})
        niches = {'Beleza': {'exclude_terms': ['vela']}, 'Eletrônicos': {'include_product_types': ['caixa de som']}}

        result = self.evaluator(llm, niches=niches, tags=('Beleza', 'Eletrônicos')).evaluate(
            [('k0', candidate('Vela LED Eletrônica', tag='Eletrônicos'))], timeout_seconds=30)

        self.assertFalse(result[0].ingest)
        self.assertEqual(result[0].source, 'rule')
        self.assertEqual(result[0].niche, 'Beleza')

    def test_incomplete_response_is_retried_then_missing_items_fail_closed(self):
        llm = Mock()
        llm.chat_completion.side_effect = [
            ToolCall('evaluate_candidates', {'decisions': [described(0, matched_brand='Samsung')]}),
            ToolCall('evaluate_candidates', {'decisions': [described(0, matched_brand='Samsung')]}),
        ]
        items = [('k0', candidate('Galaxy A15')), ('k1', candidate('Moto G84'))]

        result = self.evaluator(llm).evaluate(items, timeout_seconds=30)

        self.assertEqual(llm.chat_completion.call_count, 2)
        self.assertTrue(result[0].ingest)
        self.assertFalse(result[1].ingest)
        self.assertEqual(result[1].source, 'error')

    def test_later_listings_of_an_admitted_product_are_dropped_across_calls(self):
        llm = Mock()
        llm.chat_completion.side_effect = [
            ToolCall('evaluate_candidates', {'decisions': [
                described(0, matched_brand='Samsung', product_key='samsung|galaxy a15|smartphone'),
                described(1, matched_brand='Samsung', product_key='samsung|galaxy a15|smartphone'),
                described(2, matched_brand='Samsung'),
                described(3, matched_brand='Samsung'),
            ]}),
            ToolCall('evaluate_candidates', {'decisions': [
                described(0, matched_brand='Samsung', product_key='Samsung|Galaxy A15|Smartphone'),
            ]}),
        ]
        evaluator = self.evaluator(llm)

        first = evaluator.evaluate([('k0', candidate('Galaxy A15 loja A')), ('k1', candidate('Galaxy A15 loja B')),
                                    ('k2', candidate('Galaxy A25')), ('k3', candidate('Galaxy A35'))], timeout_seconds=30)
        second = evaluator.evaluate([('k4', candidate('Galaxy A15 loja C'))], timeout_seconds=30)

        self.assertEqual([d.ingest for d in first], [True, False, True, True])
        self.assertEqual(first[1].excluded_reason, 'duplicate product')
        self.assertFalse(second[0].ingest)

    def test_llm_failure_fails_closed(self):
        llm = Mock()
        llm.chat_completion.side_effect = LLMClientError('down')

        result = self.evaluator(llm).evaluate([('k0', candidate('Galaxy A15'))], timeout_seconds=30)

        self.assertFalse(result[0].ingest)
        self.assertEqual(result[0].source, 'error')

    def test_cached_descriptions_are_readmitted_with_the_current_rules(self):
        cache = DecisionCache()
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [
            described(0, matched_brand='Samsung')]})
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
                                           ToolCall('evaluate_candidates', {'decisions': [
                                               described(0, matched_brand='Samsung')]})]
        items = [('k0', candidate('Galaxy A15'))]

        self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)
        second = self.evaluator(llm, cache).evaluate(items, timeout_seconds=30)

        self.assertEqual(second[0].source, 'llm')


class EvaluateRequestTest(unittest.TestCase):
    def setUp(self):
        DECISION_CACHE._entries.clear()

    def body(self, **overrides):
        return {
            'tags': ['Beleza', 'Eletrônicos'],
            'relevance_profile': {'niches': {
                'Beleza': {'include_product_types': ['unhas']},
                'Eletrônicos': {'include_product_types': ['smartphone']},
            }},
            'candidates': [
                {'key': 'k-nail', 'url': 'https://shop.example/nail', 'title': 'Lixa Eletrônica de Unha', 'price': 50, 'tag': 'Eletrônicos'},
                {'key': 'k-bags', 'title': 'Kit 100 Saquinhos Eletrônicos', 'price': 10, 'tag': 'Eletrônicos'},
            ],
            **overrides,
        }

    def test_decisions_are_keyed_and_the_evaluated_niche_is_returned(self):
        llm = Mock()
        llm.chat_completion.return_value = ToolCall('evaluate_candidates', {'decisions': [
            described(0, niche='Beleza'),
            described(1, classification='off_niche'),
        ]})

        status, payload = evaluate_request(self.body(), llm_client=llm)

        self.assertEqual(status, 200)
        self.assertTrue(payload['enabled'])
        self.assertEqual([(d['key'], d['ingest'], d['niche']) for d in payload['decisions']],
                         [('k-nail', True, 'Beleza'), ('k-bags', False, 'Eletrônicos')])
        sent = llm.chat_completion.call_args.args[0][1]['content']
        self.assertIn('"listing_niche": "Eletrônicos"', sent)

    def test_without_usable_profile_nothing_is_evaluated(self):
        llm = Mock()

        status, payload = evaluate_request(self.body(relevance_profile={'niches': {'Other': {'include_brands': ['X']}}}),
                                           llm_client=llm)

        self.assertEqual((status, payload), (200, {'enabled': False, 'decisions': []}))
        llm.chat_completion.assert_not_called()

    def test_invalid_requests_are_rejected(self):
        for body in (
            self.body(candidates='x'),
            self.body(candidates=[{'key': '', 'title': 'x'}]),
            self.body(candidates=[{'key': 'k', 'title': ' '}]),
            self.body(tags='Beleza'),
            self.body(relevance_profile=[]),
            self.body(candidates=[{'key': f'k{i}', 'title': 't'} for i in range(301)]),
        ):
            status, _ = evaluate_request(body, llm_client=Mock())
            self.assertEqual(status, 400)


class RelevanceDiscoveryFlowTest(unittest.TestCase):
    def setUp(self):
        DECISION_CACHE._entries.clear()

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
        sent = []

        def send(candidates, tags, *args, **kwargs):
            sent.append((candidates[0].url, tags))
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
        return report, sent, enriched, llm

    @staticmethod
    def item(name, price='100.00'):
        return {'url': f'https://example.com/{name}', 'title': name, 'price': price}

    def test_only_admitted_candidates_are_enriched_and_ingested(self):
        phone, case, cable = self.item('Galaxy A15'), self.item('Capa Galaxy'), self.item('Cabo USB-C')
        report, sent, enriched, _ = self.run_flow(
            [phone, case, cable],
            [ToolCall('collect_page', {'candidates': [phone, case, cable]}),
             ToolCall('evaluate_candidates', {'decisions': [
                 described(0, niche='Celulares', matched_brand='Samsung', normalized_product_type='smartphone'),
                 described(1, classification='accessory', niche='Celulares'),
             ]})],
            {'niches': {'Celulares': {'include_brands': ['Samsung'], 'exclude_terms': ['capa']}}},
        )

        self.assertEqual([url for url, _ in sent], [phone['url']])
        self.assertEqual(enriched.call_count, 1)
        self.assertEqual(report['relevance']['decisions_by_classification'], {'excluded': 1, 'relevant': 1, 'accessory': 1})
        self.assertEqual(report['candidates'][0]['relevance']['normalized_product_type'], 'smartphone')
        relevance_steps = [step for step in report['steps'] if step['action'] == 'relevance']
        self.assertEqual({step['source'] for step in relevance_steps}, {'rule', 'llm'})

    def test_evaluated_niche_replaces_the_listing_niche(self):
        massager = self.item('Massageador Elétrico Corporal')
        _, sent, _, _ = self.run_flow(
            [massager],
            [ToolCall('collect_page', {'candidates': [{**massager, 'tag': 'Eletrônicos'}]}),
             ToolCall('evaluate_candidates', {'decisions': [described(0, niche='Beleza')]})],
            {'niches': {'Beleza': {'include_product_types': ['massageador']}}},
            tags=['Beleza', 'Eletrônicos'],
        )

        self.assertEqual(sent, [(massager['url'], ['Beleza'])])

    def test_without_profile_the_flow_is_unchanged(self):
        phone = self.item('Galaxy A15')
        report, sent, _, llm = self.run_flow([phone], [ToolCall('collect_page', {'candidates': [phone]})], None)

        self.assertEqual(len(sent), 1)
        self.assertEqual(llm.chat_completion.call_count, 1)
        self.assertEqual(report['relevance'], {'enabled': False, 'decisions_by_classification': {}})

    def test_niche_price_limit_is_applied_before_the_llm(self):
        cheap, expensive = self.item('Moto G24'), self.item('Galaxy S24', price='5000.00')
        _, sent, _, llm = self.run_flow(
            [cheap, expensive],
            [ToolCall('collect_page', {'candidates': [cheap, expensive]}),
             ToolCall('evaluate_candidates', {'decisions': [described(0)]})],
            {'niches': {'Celulares': {'max_price': 1500}}},
        )

        self.assertEqual([url for url, _ in sent], [cheap['url']])
        payload = llm.chat_completion.call_args_list[1].args[0][1]['content']
        self.assertNotIn('Galaxy S24', payload)


if __name__ == '__main__':
    unittest.main()
