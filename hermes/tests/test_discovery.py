"""Discovery counts persisted novelty and owns source/pagination progress."""
import sys
import time
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1] / 'src'))
from agent import Agent, check_candidates_in_pricebuddy, send_candidates_to_pricebuddy
from browser_tools import BrowserToolSet, BrowserToolResult, ProductCandidate
from browser_guard import BrowserGuard
from llm_client import LLMClientError, ToolCall


def product(name):
    return {'url': f'https://example.com/{name}', 'title': name, 'price': '100.00', 'original_price': '200.00'}


def snapshot(name, products):
    return {'url': 'https://example.com/' + name, 'text': name + '\n' + '\n'.join(p['title'] for p in products),
            'links': [{'text': p['title'], 'url': p['url']} for p in products], 'buttons': []}


class DiscoveryFlowTest(unittest.TestCase):
    def test_llm_page_segments_preserve_content_without_duplicate_links(self):
        agent = Agent(
            'example', 'electronics', allowed_hosts=['example.com'],
            browser_options={'llm_page_segment_chars': 1200},
        )
        current = {
            'url': 'https://example.com/listing',
            'text': 'Produto A\n' + ('x' * 1100) + '\nProduto B',
            'links': [
                {'text': 'Produto A', 'url': 'https://example.com/a'},
                {'text': 'Produto A', 'url': 'https://example.com/a'},
                {'text': 'Produto B', 'url': 'https://example.com/b'},
            ],
            'buttons': [],
        }

        segments = agent._build_llm_page_segments(current)

        combined_text = '\n'.join(segment for segment, _ in segments)
        links = [link['url'] for _, segment_links in segments for link in segment_links]
        self.assertIn('Produto A', combined_text)
        self.assertIn('Produto B', combined_text)
        self.assertEqual(links, ['https://example.com/a', 'https://example.com/b'])

    def test_llm_page_segments_bound_links_when_strategy_configures_a_limit(self):
        agent = Agent(
            'example', 'electronics', allowed_hosts=['example.com'],
            browser_options={'llm_page_segment_chars': 1200, 'llm_max_links_per_segment': 1},
        )
        current = snapshot('listing', [product('Produto A'), product('Produto B')])

        segments = agent._build_llm_page_segments(current)

        self.assertEqual(len(segments[0][1]), 1)
        self.assertEqual(segments[0][1][0]['url'], 'https://example.com/Produto A')

    def test_invalid_collect_page_response_is_retried(self):
        llm = Mock()
        llm.chat_completion.side_effect = [
            ToolCall('collect_page', {'candidates': 'not-an-array'}),
            ToolCall('collect_page', {'candidates': []}),
        ]
        agent = Agent(
            'example', 'electronics', llm_client=llm,
            run_timeout_seconds=10, allowed_hosts=['example.com'],
        )

        result = agent._request_collect_page([{'role': 'user', 'content': 'page'}])

        self.assertEqual(result.name, 'collect_page')
        self.assertEqual(result.arguments['candidates'], [])
        self.assertEqual(llm.chat_completion.call_count, 2)

    def test_missing_tool_call_is_retried(self):
        llm = Mock()
        llm.chat_completion.side_effect = [
            LLMClientError("LLM did not return a tool call", retryable_tool_response=True),
            ToolCall('collect_page', {'candidates': []}),
        ]
        agent = Agent(
            'example', 'electronics', llm_client=llm,
            run_timeout_seconds=10, allowed_hosts=['example.com'],
        )

        result = agent._request_collect_page([{'role': 'user', 'content': 'page'}])

        self.assertEqual(result.name, 'collect_page')
        self.assertEqual(llm.chat_completion.call_count, 2)

    def test_llm_snapshot_contains_only_new_content_for_same_listing_url(self):
        old = product('old')
        fresh = product('fresh')
        previous = snapshot('listing', [old])
        current = snapshot('listing', [old, fresh])
        current['images'] = [
            {'src': 'https://example.com/fresh.jpg', 'alt': 'Fresh product', 'order': 1},
        ]

        result = Agent._build_llm_snapshot(current, previous)

        self.assertEqual([link['url'] for link in result['links']], [fresh['url']])
        self.assertIn(fresh['title'], result['text'])
        self.assertNotIn(old['title'], result['text'])
        self.assertEqual(result['images'], current['images'])

    def test_llm_snapshot_resets_for_a_new_listing_url(self):
        old = product('old')
        fresh = product('fresh')
        previous = snapshot('page1', [old])
        current = snapshot('page2', [fresh])

        result = Agent._build_llm_snapshot(current, previous)

        self.assertEqual(result, current)

    def run_flow(self, pages, batches, known=(), min_products=2, advances=None, max_pages=15,
                 outcomes=None, metadata=None, sources=None, browser_options=None,
                 known_without_images=(), listing_images=None):
        llm = Mock()
        llm.chat_completion.side_effect = [ToolCall('collect_page', {'candidates': b}) for b in batches]
        agent = Agent('example', 'electronics', llm_client=llm, min_products=min_products,
                      starting_urls=sources or ['https://example.com/start'], allowed_hosts=['example.com'],
                      max_pages=max_pages, browser_options=browser_options)
        browser, context, page = Mock(), Mock(), Mock()
        stored = set(known)
        missing_images = set(known_without_images)
        stored.update(missing_images)
        def lookup(urls, **kwargs):
            return [{
                'url': url,
                'key': url.split('?')[0],
                'exists': url.split('?')[0] in stored,
                'has_image': url.split('?')[0] in stored and url.split('?')[0] not in missing_images,
            } for url in urls]
        def send(candidates, *args, **kwargs):
            c = candidates[0]
            state = outcomes.pop(0) if outcomes else 'success'
            if state == 'success':
                stored.add(c.url)
            return {'success': int(state == 'success'), 'existing': int(state == 'existing'),
                    'failed': int(state == 'failed'), 'created_urls': [c.url] if state == 'success' else []}
        if advances is None:
            advances = [BrowserToolResult(True, 'Next page', {'state': 'advanced', 'snapshot': p}) for p in pages[1:]]
            advances += [BrowserToolResult(True, 'No more pages', {'state': 'exhausted'})]
        with ExitStack() as stack:
            stack.enter_context(patch.object(agent, '_launch_browser', return_value=(browser, context, page)))
            stack.enter_context(patch('agent.setup_signal_handlers'))
            stack.enter_context(patch('agent.check_candidates_in_pricebuddy', side_effect=lookup))
            stack.enter_context(patch('agent.resolve_listing_images', return_value=listing_images or {}))
            sent = stack.enter_context(patch('agent.send_candidates_to_pricebuddy', side_effect=send))
            stack.enter_context(patch.object(BrowserToolSet, '_tool_navigate', return_value=BrowserToolResult(True, 'ok')))
            stack.enter_context(patch.object(BrowserToolSet, '_tool_inspect_page',
                side_effect=[BrowserToolResult(True, 'ok', p) for p in pages] if sources else None,
                return_value=BrowserToolResult(True, 'ok', pages[0])))
            advance = stack.enter_context(patch.object(BrowserToolSet, 'advance_listing', side_effect=advances))
            enriched = stack.enter_context(patch.object(BrowserToolSet, '_tool_get_product_metadata',
                side_effect=metadata, return_value=BrowserToolResult(True, 'ok', {})))
            report = agent.run()
        return report, sent, advance, enriched, llm

    def test_known_products_are_skipped_before_metadata_and_pagination_continues(self):
        old, new, last = product('old'), product('new'), product('last')
        report, sent, advance, enriched, llm = self.run_flow(
            [snapshot('page1', [old, new]), snapshot('page2', [last])], [[old, new], [last]], known=[old['url']])
        self.assertEqual(report['status'], 'completed')
        self.assertEqual(report['known_candidates_skipped'], 1)
        self.assertEqual(report['pricebuddy_submission']['success'], 2)
        self.assertEqual(enriched.call_count, 2)
        self.assertEqual(advance.call_count, 1)
        self.assertEqual(llm.chat_completion.call_count, 2)  # One call per page, not per product.

    def test_second_run_skips_first_runs_results_and_finds_new_products(self):
        old, fresh = product('old'), product('fresh')
        first, _, _, _, _ = self.run_flow([snapshot('page1', [old])], [[old]], min_products=1)
        second, _, advance, enriched, _ = self.run_flow(
            [snapshot('page1', [old]), snapshot('page2', [fresh])], [[old], [fresh]],
            known=[p['url'] for p in first['candidates']], min_products=1)
        self.assertEqual(second['candidates'][0]['url'], fresh['url'])
        self.assertEqual(enriched.call_count, 1)
        self.assertEqual(advance.call_count, 1)

    def test_next_configured_url_is_visited_after_exhaustion(self):
        fresh = product('fresh')
        urls = ['https://example.com/start', 'https://example.com/second']
        report, _, _, _, _ = self.run_flow(
            [snapshot('page1', []), snapshot('page2', [fresh])], [[], [fresh]],
            min_products=1, sources=urls,
            advances=[BrowserToolResult(True, 'No more pages', {'state': 'exhausted'})])
        self.assertEqual([s['url'] for s in report['sources']], urls)
        self.assertEqual(report['status'], 'completed')
        self.assertEqual(report['sources'][0]['state'], 'exhausted')

    def test_finish_current_page_without_truncating_at_minimum(self):
        items = [product('one'), product('two'), product('three')]
        report, sent, advance, _, _ = self.run_flow([snapshot('page1', items)], [items], min_products=1)
        self.assertEqual(report['selected_candidates'], 3)
        self.assertEqual(sent.call_count, 3)
        advance.assert_not_called()

    def test_updates_and_failed_insertions_do_not_count_and_search_continues(self):
        items = [product('race'), product('failed')]
        last = product('new')
        report, _, advance, _, _ = self.run_flow(
            [snapshot('page1', items), snapshot('page2', [last])], [items, [last]], min_products=1,
            outcomes=['existing', 'failed', 'success'])
        self.assertEqual(report['pricebuddy_submission'], {'success': 1, 'existing': 1, 'failed': 1})
        self.assertTrue(report['target_reached'])
        self.assertEqual(advance.call_count, 1)

    def test_changed_product_price_is_rejected_and_replaced(self):
        stale, fresh = product('stale'), product('fresh')
        report, sent, _, _, _ = self.run_flow(
            [snapshot('page1', [stale]), snapshot('page2', [fresh])], [[stale], [fresh]], min_products=1,
            metadata=[BrowserToolResult(True, 'ok', {'price': '195.00'}), BrowserToolResult(True, 'ok', {'price': '100.00'})])
        self.assertEqual(report['rejected_candidates'], 1)
        self.assertEqual(sent.call_count, 1)
        self.assertEqual(report['candidates'][0]['title'], 'fresh')

    def test_strategy_can_require_an_image_without_changing_the_default(self):
        item = product('without-image')
        report, sent, _, _, _ = self.run_flow(
            [snapshot('page1', [item])], [[item]], min_products=1,
            browser_options={'require_image': True},
        )

        sent.assert_not_called()
        self.assertEqual(report['status'], 'incomplete')
        self.assertEqual(report['rejected_candidates'], 1)
        self.assertEqual(report['candidates'], [])

    def test_structured_listing_image_backfills_an_existing_product(self):
        item = product('existing-without-image')
        image = 'https://http2.mlstatic.com/D_Q_NP_2X_123-MLA456-AB.webp'
        report, sent, _, enriched, _ = self.run_flow(
            [snapshot('page1', [item])],
            [[item]],
            min_products=1,
            known_without_images=[item['url']],
            outcomes=['existing'],
            listing_images={item['url']: image},
            browser_options={'listing_image_enrichment': True, 'require_image': True},
        )

        self.assertEqual(report['pricebuddy_submission']['existing'], 1)
        self.assertEqual(report['known_candidates_skipped'], 0)
        self.assertEqual(sent.call_args.args[0][0].image_url, image)
        enriched.assert_not_called()

    def test_repeated_page_is_stalled_not_exhausted(self):
        page = snapshot('page1', [])
        report, _, _, _, llm = self.run_flow([page, page], [[], []])
        self.assertEqual(report['status'], 'incomplete')
        self.assertEqual(report['sources'][0]['state'], 'stalled')
        self.assertFalse(report['sources_exhausted'])
        self.assertEqual(llm.chat_completion.call_count, 1)

    def test_safety_page_limit_returns_incomplete(self):
        report, _, advance, _, _ = self.run_flow([snapshot('page1', [])], [[]], max_pages=1)
        self.assertEqual(report['status'], 'incomplete')
        self.assertIn('page limit', report['abort_reason'])
        advance.assert_not_called()

    def test_exhausted_sources_do_not_claim_target_reached(self):
        report, _, _, _, _ = self.run_flow([snapshot('page1', [])], [[]])
        self.assertEqual(report['status'], 'incomplete')
        self.assertTrue(report['sources_exhausted'])
        self.assertFalse(report['target_reached'])

    def test_invented_links_never_reach_lookup_or_metadata(self):
        report, sent, _, enriched, _ = self.run_flow([snapshot('page1', [])], [[product('invented')]])
        sent.assert_not_called()
        enriched.assert_not_called()
        self.assertEqual(report['rejected_candidates'], 1)

    def test_normalized_duplicate_in_same_batch_is_only_enriched_once(self):
        first = product('same')
        second = {**first, 'url': first['url'] + '?utm_source=tracking'}
        report, sent, _, enriched, _ = self.run_flow([snapshot('page1', [first, second])], [[first, second]], min_products=1)
        self.assertEqual(report['selected_candidates'], 1)
        self.assertEqual(enriched.call_count, 1)

    def test_lookup_failure_aborts_before_browser_launch(self):
        agent = Agent('example', 'electronics', starting_urls=['https://example.com/start'])
        with patch('agent.check_candidates_in_pricebuddy', side_effect=RuntimeError('API unavailable')), \
             patch.object(agent, '_launch_browser') as launch:
            report = agent.run()
        self.assertEqual(report['status'], 'error')
        launch.assert_not_called()

    def test_timeout_retains_previously_created_products(self):
        agent = Agent('example', 'electronics', run_timeout_seconds=1)
        agent.start_time = time.time() - 2
        agent.created_candidates = [ProductCandidate(**product('saved'))]
        self.assertFalse(agent._can_continue())
        report = agent._build_report()
        self.assertEqual(report['status'], 'incomplete')
        self.assertEqual(len(report['candidates']), 1)


class SubmissionTest(unittest.TestCase):
    @patch('agent.config.PRICEBUDDY_API_TOKEN', 'test-token')
    @patch('agent.config.PRICEBUDDY_API_BASE_URL', 'http://app/api')
    @patch('agent.requests.post')
    def test_only_explicit_api_creation_counts(self, post):
        responses = [Mock(status_code=200), Mock(status_code=201), Mock(status_code=422)]
        responses[0].json.return_value = {'created': False}
        responses[1].json.return_value = {'created': True}
        post.side_effect = responses
        result = send_candidates_to_pricebuddy([ProductCandidate(**product(n)) for n in ['existing', 'new', 'bad']])
        self.assertEqual(result, {'success': 1, 'existing': 1, 'failed': 1, 'created_urls': ['https://example.com/new']})


class PaginationTest(unittest.TestCase):
    def setUp(self):
        self.page = Mock()
        self.tools = BrowserToolSet(self.page, BrowserGuard(['example.com']))

    def test_next_link_is_clicked_even_without_llm_suggestion(self):
        current = snapshot('page1', [])
        current['links'] = [{'text': 'Próxima página', 'url': 'https://example.com/page2', 'disabled': False}]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(True, 'clicked')) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', snapshot('page2', []))):
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('Próxima página')
        self.assertEqual(result.data['state'], 'advanced')

    def test_arbitrary_category_link_cannot_replace_pagination(self):
        current = snapshot('page1', [])
        current['links'] = [{'text': 'Smartphones', 'url': 'https://example.com/smartphones'}]
        self.page.evaluate.return_value = True
        with patch.object(self.tools, '_tool_click') as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', current)):
            result = self.tools.advance_listing(current, 'Smartphones')
        click.assert_not_called()
        self.assertEqual(result.data['state'], 'exhausted')
        self.assertEqual(self.page.mouse.wheel.call_count, 2)

    def test_lazy_loading_counts_only_when_content_changes(self):
        with patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', snapshot('loaded', []))):
            result = self.tools.advance_listing(snapshot('page1', []))
        self.assertEqual(result.data['state'], 'advanced')

    def test_unchanged_pagination_stalls(self):
        current = snapshot('page1', [])
        current['buttons'] = [{'text': 'Carregar mais', 'disabled': False}]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(True, 'clicked')), \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', current)):
            result = self.tools.advance_listing(current)
        self.assertEqual(result.data['state'], 'stalled')

    def test_click_falls_back_to_scroll_when_pagination_fails(self):
        current = snapshot('page1', [])
        current['links'] = [{'text': 'Ver mais', 'url': 'https://example.com/more', 'disabled': False}]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(
            False, "Click failed: no element found with visible text 'Ver mais'"
        )) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(
                 True, 'ok', snapshot('loaded', []))
             ) as inspect:
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('Ver mais')
        inspect.assert_called()
        self.assertEqual(result.data['state'], 'advanced')
        self.assertIn('scroll', result.message.lower())

    def test_click_fails_and_scroll_exhausts(self):
        current = snapshot('page1', [])
        current['links'] = [{'text': 'Ver mais', 'url': 'https://example.com/more', 'disabled': False}]
        self.page.evaluate.return_value = True
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(
            False, "Click failed: no element found with visible text 'Ver mais'"
        )) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', current)):
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('Ver mais')
        self.assertEqual(result.data['state'], 'exhausted')

    def test_click_fails_then_stalls(self):
        current = snapshot('page1', [])
        current['links'] = [{'text': 'Ver mais', 'url': 'https://example.com/more', 'disabled': False}]
        self.page.evaluate.return_value = False
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(
            False, "Click failed: no element found with visible text 'Ver mais'"
        )) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(True, 'ok', current)):
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('Ver mais')
        self.assertEqual(result.data['state'], 'stalled')

    def test_numeric_pagination_advances_sequentially(self):
        current = snapshot('page1', [])
        current['url'] = 'https://example.com/list'
        current['links'] = [
            {'text': '1', 'url': 'https://example.com/list?page=1', 'disabled': False},
            {'text': '2', 'url': 'https://example.com/list?page=2', 'disabled': False},
            {'text': '3', 'url': 'https://example.com/list?page=3', 'disabled': False},
        ]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(True, 'clicked')) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(
                 True, 'ok', snapshot('page2', []))
             ):
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('2')
        self.assertEqual(result.data['state'], 'advanced')

    def test_numeric_pagination_does_not_repeat_current_page(self):
        current = snapshot('page2', [])
        current['url'] = 'https://example.com/list?page=2'
        current['links'] = [
            {'text': '1', 'url': 'https://example.com/list?page=1', 'disabled': False},
            {'text': '2', 'url': 'https://example.com/list?page=2', 'disabled': False},
            {'text': '3', 'url': 'https://example.com/list?page=3', 'disabled': False},
        ]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(True, 'clicked')) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(
                 True, 'ok', snapshot('page3', []))
             ):
            result = self.tools.advance_listing(current)
        click.assert_called_once_with('3')
        self.assertEqual(result.data['state'], 'advanced')

    def test_numeric_pagination_uses_llm_suggestion(self):
        current = snapshot('page1', [])
        current['url'] = 'https://example.com/list'
        current['links'] = [
            {'text': '1', 'url': 'https://example.com/list?page=1', 'disabled': False},
            {'text': '2', 'url': 'https://example.com/list?page=2', 'disabled': False},
        ]
        with patch.object(self.tools, '_tool_click', return_value=BrowserToolResult(True, 'clicked')) as click, \
             patch.object(self.tools, '_tool_inspect_page', return_value=BrowserToolResult(
                 True, 'ok', snapshot('page2', []))
             ):
            result = self.tools.advance_listing(current, next_page_text='2')
        click.assert_called_once_with('2')
        self.assertEqual(result.data['state'], 'advanced')


if __name__ == '__main__':
    unittest.main()
