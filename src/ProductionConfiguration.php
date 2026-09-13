<?php

namespace MrNewport\LaravelPreflight;

use Illuminate\Contracts\Config\Repository;

final readonly class ProductionConfiguration implements Check
{
    public function __construct(private Repository $config) {}

    public function run(): array
    {
        $key = $this->config->get('app.key');
        $decoded = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $cipher = strtolower((string) $this->config->get('app.cipher', 'AES-256-CBC'));
        $keyLength = match ($cipher) {'aes-128-cbc', 'aes-128-gcm' => 16, 'aes-256-cbc', 'aes-256-gcm' => 32, default => 0};
        $url = parse_url((string) $this->config->get('app.url'));
        $shared = ['database', 'redis', 'dynamodb', 'memcached'];
        $session = $this->config->get('session.driver');
        $sessionCache = $this->config->get('session.store') ?: ($session === 'cache' ? $this->config->get('cache.default') : $session);
        $results = [
            Result::assertion('app.debug', $this->config->get('app.debug') === false, 'Disable application debug mode.'),
            Result::assertion('app.key', $keyLength > 0 && is_string($decoded) && strlen($decoded) === $keyLength, 'Configure a valid application key for the selected cipher.'),
            Result::assertion('app.url', is_array($url) && ($url['scheme'] ?? '') === 'https' && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']), 'Configure an HTTPS application URL without credentials.'),
            Result::assertion('session.secure', $this->config->get('session.secure') === true, 'Enable secure session cookies.'),
            Result::assertion('session.http_only', $this->config->get('session.http_only') === true, 'Enable HttpOnly session cookies.'),
        ];
        if ($this->config->get('preflight.require_shared_state', true)) {
            $cacheStore = $this->config->get('cache.default');
            $cacheDriver = $this->config->get('cache.stores.'.$cacheStore.'.driver', $cacheStore);
            $sessionDriver = $this->config->get('cache.stores.'.$sessionCache.'.driver', $sessionCache);
            $results[] = Result::assertion('cache.shared', in_array($cacheDriver, $shared, true), 'Use a durable shared cache for distributed throttling.');
            $results[] = Result::assertion('session.shared', $session === 'database' || (in_array($session, ['cache', 'redis', 'dynamodb', 'memcached'], true) && in_array($sessionDriver, $shared, true)), 'Use a shared server-side session store.');
        }
        return $results;
    }
}
