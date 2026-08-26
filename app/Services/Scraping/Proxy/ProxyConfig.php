<?php

namespace App\Services\Scraping\Proxy;

use Illuminate\Support\Str;

final class ProxyConfig
{
    public function __construct(
        public readonly string $id,
        public readonly string $host,
        public readonly int $port,
        public readonly string $protocol = 'http',
        public readonly ?string $username = null,
        public readonly ?string $password = null,
    ) {}

    public function scraperOption(): string
    {
        $credentials = $this->username !== null
            ? rawurlencode($this->username).':'.rawurlencode((string) $this->password).'@'
            : '';

        return $this->protocol.'://'.$credentials.$this->host.':'.$this->port;
    }

    public function logContext(): array
    {
        return [
            'proxy_id' => $this->id,
            'proxy_host_hash' => hash('sha256', $this->host),
        ];
    }

    public static function fromString(string $value, ?string $id = null): ?self
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $uri = parse_url(str_contains($value, '://') ? $value : 'http://'.$value);
        if (! is_array($uri) || empty($uri['host']) || empty($uri['port'])) {
            return null;
        }

        $host = (string) $uri['host'];
        $port = (int) $uri['port'];
        $protocol = strtolower((string) ($uri['scheme'] ?? 'http'));
        $generatedId = $id ?: 'proxy-'.Str::lower(substr(hash('sha256', $protocol.'|'.$host.'|'.$port), 0, 12));

        return new self(
            $generatedId,
            $host,
            $port,
            $protocol,
            isset($uri['user']) ? rawurldecode((string) $uri['user']) : null,
            isset($uri['pass']) ? rawurldecode((string) $uri['pass']) : null,
        );
    }
}
