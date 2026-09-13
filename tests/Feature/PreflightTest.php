<?php

use Illuminate\Support\Facades\Artisan;
use MrNewport\LaravelPreflight\{Check, Result, Runner, ProductionConfiguration, VaporManifest};

function validManifest(): array {
    return ['ignore' => ['.env*'], 'environments' => ['production' => ['domain' => 'example.com', 'concurrency' => 5, 'cli-concurrency' => 2, 'warm' => 0, 'memory' => 512]]];
}

it('checks a manifest without contacting AWS and detects ignored domain keys and excess limits', function () {
    $runner = new Runner();
    expect($runner->passes((new VaporManifest(validManifest()))->run()))->toBeTrue();
    $manifest = validManifest();
    $manifest['environments']['production']['concurrency'] = 100;
    $manifest['environments']['staging'] = ['domains' => ['example.org']];
    $results = (new VaporManifest($manifest))->run();
    expect($runner->passes($results))->toBeFalse();
    expect(collect($results)->where('status', 'fail')->pluck('name')->all())->toBe(['vapor.domain_key', 'vapor.concurrency']);
});

it('rejects unbound, incomplete, and loosely typed manifests', function () {
    foreach ([[], ['environments' => ['production' => []]], ['environments' => 'invalid']] as $manifest) {
        expect((new Runner())->passes((new VaporManifest($manifest))->run()))->toBeFalse();
    }
    $manifest = validManifest();
    $manifest['environments']['production']['concurrency'] = '5';
    expect((new Runner())->passes((new VaporManifest($manifest))->run()))->toBeFalse();
});

it('checks shared production state without returning configuration secrets', function () {
    config(['app.debug' => false, 'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.url' => 'https://example.com', 'session.secure' => true, 'session.http_only' => true, 'session.driver' => 'database', 'cache.default' => 'database']);
    $check = app(ProductionConfiguration::class);
    expect((new Runner())->passes($check->run()))->toBeTrue();
    config(['app.key' => 'highly-private-invalid-key', 'app.debug' => true, 'cache.default' => 'file']);
    $results = $check->run();
    expect((new Runner())->passes($results))->toBeFalse();
    expect(json_encode($results))->not->toContain('highly-private-invalid-key');
});

it('fails closed when a custom check crashes without leaking its exception', function () {
    $check = new class implements Check {
        public function run(): array { throw new RuntimeException('password=private'); }
    };
    $runner = new Runner();
    $results = $runner->run([$check]);
    expect($runner->passes($results))->toBeFalse()->and(json_encode($results))->not->toContain('password=private');
    expect($runner->passes([new Result('optional', 'warn', 'Review.')]))->toBeTrue();
    expect($runner->passes([new Result('optional', 'warn', 'Review.')], true))->toBeFalse();
});

it('returns machine readable failure for a missing manifest or invalid profile', function () {
    foreach ([['--profile' => 'vapor', '--manifest' => '/missing/manifest.yml'], ['--profile' => 'invalid']] as $args) {
        expect(Artisan::call('preflight:check', $args + ['--json' => true]))->toBe(1);
        expect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR)['passed'])->toBeFalse();
    }
});

it('uses registered Artisan commands and leaves a valid manifest unchanged', function () {
    $path = tempnam(sys_get_temp_dir(), 'preflight-');
    try {
        file_put_contents($path, \Symfony\Component\Yaml\Yaml::dump(validManifest(), 5));
        $before = hash_file('sha256', $path);
        expect(Artisan::call('preflight:check', ['--profile' => 'vapor', '--manifest' => $path, '--json' => true]))->toBe(0);
        expect(json_decode(Artisan::output(), true)['passed'])->toBeTrue();
        expect(hash_file('sha256', $path))->toBe($before);
    } finally { unlink($path); }
});
