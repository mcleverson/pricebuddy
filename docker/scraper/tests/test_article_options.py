import hashlib

from endpoints.article import (
    apply_driver_options,
    build_driver_kwargs,
    parse_extra_http_headers,
    proxy_capability_host_hash,
    proxy_host_hash,
)


def test_build_driver_kwargs_forwards_browser_options_to_driver():
    kwargs = build_driver_kwargs(
        True,
        True,
        proxy='http://user:password@proxy.example:8080',
        locale='pt-BR',
        user_agent='PriceBuddyTest/1.0',
        wait_until='domcontentloaded',
    )

    assert kwargs['proxy'] == 'http://user:password@proxy.example:8080'
    assert kwargs['locale'] == 'pt-BR'
    assert kwargs['agent'] == 'PriceBuddyTest/1.0'
    assert kwargs['page_load_strategy'] == 'eager'


def test_headers_are_parsed_without_logging_or_transforming_values():
    assert parse_extra_http_headers('Accept-Language:pt-BR;X-Test:value:with:colon') == {
        'Accept-Language': 'pt-BR',
        'X-Test': 'value:with:colon',
    }


def test_proxy_diagnostic_contains_only_a_host_hash():
    value = proxy_host_hash('http://user:password@proxy.example:8080')

    assert value == hashlib.sha256(b'proxy.example').hexdigest()
    assert 'password' not in value


def test_proxy_diagnostic_can_match_driver_capability_without_credentials():
    value = proxy_capability_host_hash({
        'proxy': {
            'proxyType': 'manual',
            'httpProxy': 'proxy.example:8080',
        },
    })

    assert value == hashlib.sha256(b'proxy.example').hexdigest()


def test_cdp_options_are_applied_to_the_live_driver():
    class FakeDriver:
        def __init__(self):
            self.calls = []

        def set_page_load_timeout(self, value):
            self.calls.append(('timeout', value))

        def set_window_size(self, width, height):
            self.calls.append(('viewport', width, height))

        def execute_cdp_cmd(self, name, params):
            self.calls.append((name, params))

    driver = FakeDriver()
    applied = apply_driver_options(
        driver,
        timeout=15000,
        viewport_width=1280,
        viewport_height=720,
        timezone='America/Sao_Paulo',
        extra_http_headers='X-PriceBuddy-Test:smoke',
    )

    assert applied == ['timeout', 'viewport', 'timezone', 'extra_http_headers']
    assert ('Emulation.setTimezoneOverride', {'timezoneId': 'America/Sao_Paulo'}) in driver.calls
    assert ('Network.setExtraHTTPHeaders', {'headers': {'X-PriceBuddy-Test': 'smoke'}}) in driver.calls
