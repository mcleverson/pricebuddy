from pathlib import Path
import re

ARTICLE = Path('/SeleniumBase/api/endpoints/article.py')
source = ARTICLE.read_text()


def replace_once(old, new, label):
    global source
    count = source.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 match, found {count}')
    source = source.replace(old, new, 1)


def replace_regex(pattern, new, label):
    global source
    source, count = re.subn(pattern, new, source, count=1, flags=re.S)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 match, found {count}')


helpers = '''
def parse_extra_http_headers(value):
    """Parse the scraper API's ``Header:value;Header2:value`` format."""
    headers = {}
    for item in (value or '').split(';'):
        name, separator, header_value = item.partition(':')
        name = name.strip()
        if separator and name:
            headers[name] = header_value.strip()
    return headers


def proxy_host_hash(proxy):
    """Return a non-sensitive identifier for a configured proxy host."""
    if not proxy:
        return None

    candidate = proxy if '://' in proxy else '//' + proxy
    hostname = urlparse(candidate).hostname
    return hashlib.sha256(hostname.encode()).hexdigest() if hostname else None


def proxy_capability_host_hash(capabilities):
    """Hash the proxy host reported by Selenium without exposing its value."""
    proxy = (capabilities or {}).get('proxy') or {}
    for key in ('httpProxy', 'sslProxy', 'socksProxy'):
        value = proxy.get(key)
        if value:
            return proxy_host_hash(value)
    return None


def build_driver_kwargs(incognito, use_uc_mode, proxy=None, locale='',
                        user_agent='', wait_until=''):
    """Translate API options into arguments accepted by seleniumbase.Driver."""
    kwargs = {
        'browser': 'chrome',
        'headless': True,
        'uc': use_uc_mode,
        'incognito': incognito,
    }

    if proxy:
        kwargs['proxy'] = proxy
    if locale:
        # ``locale`` is SeleniumBase's alias for ``locale_code``.
        kwargs['locale'] = locale
    if user_agent:
        kwargs['agent'] = user_agent

    page_load_strategy = {
        'load': 'normal',
        'domcontentloaded': 'eager',
        'commit': 'none',
        'networkidle': 'normal',
    }.get(wait_until)
    if page_load_strategy:
        kwargs['page_load_strategy'] = page_load_strategy

    return kwargs


def apply_driver_options(driver, timeout, viewport_width, viewport_height,
                         timezone='', extra_http_headers=''):
    """Apply options that require the live Chrome DevTools session."""
    applied = []

    if timeout > 0:
        driver.set_page_load_timeout(timeout / 1000.0)
        applied.append('timeout')

    if viewport_width and viewport_height:
        driver.set_window_size(viewport_width, viewport_height)
        applied.append('viewport')

    if timezone:
        driver.execute_cdp_cmd('Emulation.setTimezoneOverride', {
            'timezoneId': timezone,
        })
        applied.append('timezone')

    headers = parse_extra_http_headers(extra_http_headers)
    if headers:
        driver.execute_cdp_cmd('Network.enable', {})
        driver.execute_cdp_cmd('Network.setExtraHTTPHeaders', {
            'headers': headers,
        })
        applied.append('extra_http_headers')

    return applied


'''
replace_once(
    'def register_routes(app, cache_dir, user_scripts_dir, screenshots_dir, ',
    helpers + 'def register_routes(app, cache_dir, user_scripts_dir, screenshots_dir, ',
    'driver option helpers',
)

replace_once(
    "            extra_http_headers = request.args.get('extra-http-headers', default_extra_http_headers)\n",
    "            extra_http_headers = request.args.get('extra-http-headers', default_extra_http_headers)\n"
    "            proxy = request.args.get('proxy')\n",
    'proxy request option',
)

replace_once(
    "                'timezone': timezone,\n",
    "                'timezone': timezone,\n"
    "                'proxy-host-hash': proxy_host_hash(proxy),\n",
    'proxy-aware cache key',
)

driver_setup = '''            driver_kwargs = build_driver_kwargs(
                incognito,
                use_uc_mode,
                proxy=proxy,
                locale=locale,
                user_agent=user_agent,
                wait_until=wait_until,
            )
            logger.info(
                "Driver options: proxy=%s proxy_host_hash=%s locale=%s timezone=%s user_agent=%s headers=%s",
                bool(proxy),
                proxy_host_hash(proxy),
                bool(locale),
                bool(timezone),
                bool(user_agent),
                bool(extra_http_headers),
            )
'''
replace_regex(
    r"            # Initialize SeleniumBase Driver with configuration\n.*?(?=            driver = None)",
    "            # Initialize SeleniumBase Driver with configuration\n" + driver_setup,
    'Driver constructor options',
)

startup = '''            driver = None
            scrape_meta = {
                'proxyConfigured': bool(proxy),
                'proxyEffective': False,
                'proxyHostHash': proxy_host_hash(proxy),
                'effectiveProxyHostHash': None,
                'browserOptionsApplied': [],
            }
            try:
                driver = Driver(**driver_kwargs)
                capabilities = driver.capabilities or {}
                scrape_meta['proxyEffective'] = bool(capabilities.get('proxy'))
                scrape_meta['effectiveProxyHostHash'] = proxy_capability_host_hash(capabilities)
                scrape_meta['browserOptionsApplied'] = apply_driver_options(
                    driver,
                    timeout,
                    viewport_width,
                    viewport_height,
                    timezone,
                    extra_http_headers,
                )
                if proxy:
                    scrape_meta['browserOptionsApplied'].append('proxy')
                if locale:
                    scrape_meta['browserOptionsApplied'].append('locale')
                if user_agent:
                    scrape_meta['browserOptionsApplied'].append('user_agent')
                if wait_until:
                    scrape_meta['browserOptionsApplied'].append('wait_until')
                logger.info(
                    "Driver started: proxy_effective=%s proxy_host_hash=%s",
                    scrape_meta['proxyEffective'],
                    scrape_meta['proxyHostHash'],
                )
'''
replace_regex(
    r"            driver = None\n            try:\n                driver = Driver\(\*\*driver_kwargs\)\n.*?(?=                # Navigate to the URL)",
    startup,
    'Driver startup diagnostics',
)

replace_once(
    "            query.update({k: v for k, v in request.args.items() if k != 'url'})\n",
    "            query.update({k: v for k, v in request.args.items()\n"
    "                          if k not in {'url', 'proxy', 'http-credentials', 'extra-http-headers'}})\n",
    'sensitive query response fields',
)

replace_once(
    "                'screenshotUri': screenshot_uri\n",
    "                'screenshotUri': screenshot_uri,\n"
    "                'scrapeMeta': scrape_meta,\n",
    'scrape diagnostic response field',
)

ARTICLE.write_text(source)
