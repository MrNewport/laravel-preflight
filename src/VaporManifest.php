<?php

namespace MrNewport\LaravelPreflight;

final readonly class VaporManifest implements Check
{
    public function __construct(private array $manifest, private string $environment = 'production', private array $limits = [], private mixed $environmentFiles = null, private ?string $manifestDirectory = null) {}

    public function run(): array
    {
        $environments = $this->manifest['environments'] ?? [];
        if (!is_array($environments) || !is_array($environments[$this->environment] ?? null)) {
            return [new Result('vapor.environment', 'fail', 'The selected Vapor environment is missing or invalid.')];
        }
        $settings = $environments[$this->environment];
        $results = [];
        $badDomains = false;
        foreach ($environments as $environment) {
            if (is_array($environment) && array_key_exists('domains', $environment)) $badDomains = true;
        }
        $domain = $settings['domain'] ?? null;
        $domains = is_array($domain) ? $domain : [$domain];
        $validDomains = $domains !== [];
        foreach ($domains as $host) {
            $validDomains = $validDomains && is_string($host) && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && str_contains($host, '.');
        }
        $results[] = Result::assertion('vapor.domain_key', !$badDomains, 'Use Vapor\'s singular domain key, including for arrays of domains.');
        $results[] = Result::assertion('vapor.domain', $validDomains, 'Bind at least one valid hostname in the selected environment.');
        $limits = $this->limits + ['concurrency' => 5, 'cli-concurrency' => 2, 'warm' => 0, 'memory' => 512];
        foreach ($limits as $key => $maximum) {
            $value = $settings[$key] ?? null;
            $minimum = $key === 'warm' ? 0 : 1;
            $results[] = Result::assertion('vapor.'.$key, is_int($maximum) && $maximum >= $minimum && is_int($value) && $value >= $minimum && $value <= $maximum, 'Set an explicit integer within the configured limit for '.$key.'.');
        }
        $results[] = VaporEnvironmentFiles::check($this->manifest['ignore'] ?? [],
            $settings, $this->environment, $this->environmentFiles, $this->manifestDirectory);
        return $results;
    }
}
