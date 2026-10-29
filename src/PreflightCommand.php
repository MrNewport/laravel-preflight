<?php

namespace MrNewport\LaravelPreflight;

use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class PreflightCommand extends Command
{
    protected $signature = 'preflight:check {--profile=all : all, production, or vapor} {--manifest= : Defaults to vapor.yml} {--environment=production} {--json} {--strict : Treat warnings as failures}';
    protected $description = 'Check release configuration without changing files or contacting cloud services';

    public function handle(Runner $runner): int
    {
        $profile = $this->option('profile');
        $checks = [];
        $results = [];
        if (!in_array($profile, ['all', 'production', 'vapor'], true)) {
            $results[] = new Result('profile', 'fail', 'Choose all, production, or vapor.');
        } else {
            if ($profile !== 'vapor') $checks[] = $this->laravel->make(ProductionConfiguration::class);
            if ($profile !== 'production') {
                try {
                    $path = $this->option('manifest') ?: base_path('vapor.yml');
                    // parseFile only accepts a local file; reject URL wrappers before reading.
                    if (!is_string($path) || str_contains($path, '://') || !is_file($path)) throw new \RuntimeException();
                    $realPath = realpath($path);
                    if ($realPath === false) throw new \RuntimeException();
                    $manifest = Yaml::parseFile($path);
                    if (!is_array($manifest)) throw new \RuntimeException();
                    $checks[] = new VaporManifest($manifest, $this->option('environment'),
                        config('preflight.vapor_limits', []), config('preflight.vapor_environment_files'), dirname($realPath));
                } catch (\Throwable) {
                    $results[] = new Result('vapor.manifest', 'fail', 'A readable local YAML manifest is required.');
                }
            }
            foreach (config('preflight.checks', []) as $class) {
                try {
                    $check = $this->laravel->make($class);
                    if (!$check instanceof Check) throw new \RuntimeException();
                    $checks[] = $check;
                } catch (\Throwable) {
                    $results[] = new Result('custom_check', 'fail', 'A configured check could not be resolved.');
                }
            }
        }
        $results = array_merge($results, $runner->run($checks));
        $passed = $runner->passes($results, (bool) $this->option('strict'));
        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $passed, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($results as $result) $this->line(strtoupper($result->status).' '.$result->name.': '.$result->message);
        }
        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
