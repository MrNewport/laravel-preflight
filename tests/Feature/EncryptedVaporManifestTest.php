<?php

use Illuminate\Support\Facades\Artisan;
use MrNewport\LaravelPreflight\Runner;
use MrNewport\LaravelPreflight\VaporManifest;
use Symfony\Component\Yaml\Yaml;

function encryptedPolicy(): array
{
    return ['mode' => 'named-encrypted',
        'plaintext_exclusions' => ['.env', '.env.backup', '.env.local', '.env.production', '.env.staging', '.env.testing'],
        'encrypted_files' => ['production' => '.env.production.encrypted', 'staging' => '.env.staging.encrypted'],
        'guard_command' => 'php bootstrap/check-vapor-encrypted-environment.php {environment}'];
}

function fixtureRoot(): string
{
    return __DIR__.'/../Fixtures';
}

function namedEncryptedVaporManifest(): array
{
    return Yaml::parseFile(__DIR__.'/../Fixtures/encrypted-vapor.yml');
}

it('preserves the original strict default while allowing the configured named encrypted policy', function () {
    $manifest = namedEncryptedVaporManifest();
    $runner = new Runner;
    $original = (new VaporManifest($manifest, 'production'))->run();
    expect($runner->passes($original))->toBeFalse()
        ->and(collect($original)->where('status', 'fail')->pluck('name')->all())->toBe(['vapor.environment_files']);
    foreach (['production', 'staging'] as $environment) {
        expect($runner->passes((new VaporManifest($manifest, $environment, [], encryptedPolicy(), fixtureRoot()))->run()))->toBeTrue();
    }
});

it('fails closed for missing exclusions, ignored encrypted files, absent first guard and invalid policies', function () {
    $runner = new Runner;
    $base = namedEncryptedVaporManifest();
    $cases = [];
    $missing = $base; $missing['ignore'] = array_values(array_diff($missing['ignore'], ['.env.production'])); $cases[] = [$missing, encryptedPolicy()];
    $ignored = $base; $ignored['ignore'][] = '.env.*'; $cases[] = [$ignored, encryptedPolicy()];
    $noGuard = $base; $noGuard['environments']['production']['build'][0] = 'composer install'; $cases[] = [$noGuard, encryptedPolicy()];
    $cases[] = [$base, ['mode' => 'named-encrypted']];
    $cases[] = [$base, ['mode' => 'named-encrypted', 'plaintext_exclusions' => [['.env']],
        'encrypted_files' => ['production' => '.env.production.encrypted'], 'guard_command' => 'guard {environment}']];
    $cases[] = [$base, ['mode' => 'named-encrypted', 'plaintext_exclusions' => ['SECRET=fictional'],
        'encrypted_files' => ['production' => '.env.production.encrypted'], 'guard_command' => 'guard {environment}']];
    $missingRoot = encryptedPolicy(); $missingRoot['plaintext_exclusions'] = ['.env.local']; $cases[] = [$base, $missingRoot];
    $wrongBinding = encryptedPolicy(); $wrongBinding['encrypted_files']['production'] = '.env.staging.encrypted'; $cases[] = [$base, $wrongBinding];
    $unsafeGuard = encryptedPolicy(); $unsafeGuard['guard_command'] .= '; echo SECRET=fictional'; $cases[] = [$base, $unsafeGuard];
    foreach ($cases as [$manifest, $policy]) {
        $results = (new VaporManifest($manifest, 'production', [], $policy, fixtureRoot()))->run();
        expect($runner->passes($results))->toBeFalse()
            ->and(collect($results)->where('status', 'fail')->pluck('name')->all())->toContain('vapor.environment_files')
            ->and(json_encode($results))->not->toContain('SECRET=fictional');
    }
});

it('wires the configured policy through the Artisan manifest read without modifying the fixture', function () {
    $manifest = __DIR__.'/../Fixtures/encrypted-vapor.yml';
    $hash = hash_file('sha256', $manifest);
    config(['preflight.vapor_environment_files' => encryptedPolicy()]);
    foreach (['production', 'staging'] as $environment) {
        expect(Artisan::call('preflight:check', ['--profile' => 'vapor', '--manifest' => $manifest,
            '--environment' => $environment, '--json' => true]))->toBe(0);
        $body = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        expect($body['passed'])->toBeTrue();
    }
    config(['preflight.vapor_environment_files' => ['invalid' => true]]);
    expect(Artisan::call('preflight:check', ['--profile' => 'vapor', '--manifest' => $manifest,
        '--environment' => 'production', '--json' => true]))->toBe(1);
    expect(hash_file('sha256', $manifest))->toBe($hash);
});

it('requires the root plaintext environment file even if a configured allowlist omits it', function () {
    $manifest = namedEncryptedVaporManifest();
    $manifest['ignore'] = ['.env.local'];
    $policy = encryptedPolicy();
    $policy['plaintext_exclusions'] = ['.env.local'];
    $results = (new VaporManifest($manifest, 'production', [], $policy, fixtureRoot()))->run();
    expect((new Runner)->passes($results))->toBeFalse();
});

it('binds an added environment to its own plaintext exclusion and encrypted artifact', function () {
    $manifest = namedEncryptedVaporManifest();
    $manifest['environments']['qa'] = $manifest['environments']['staging'];
    $manifest['environments']['qa']['domain'] = 'qa.fictional.example';
    $manifest['environments']['qa']['build'][0] = 'php bootstrap/check-vapor-encrypted-environment.php qa';
    $policy = encryptedPolicy();
    $policy['encrypted_files']['qa'] = '.env.qa.encrypted';
    $runner = new Runner;
    expect($runner->passes((new VaporManifest($manifest, 'qa', [], $policy, fixtureRoot()))->run()))->toBeFalse();
    $policy['plaintext_exclusions'][] = '.env.qa';
    $manifest['ignore'][] = '.env.qa';
    expect($runner->passes((new VaporManifest($manifest, 'qa', [], $policy, fixtureRoot()))->run()))->toBeTrue();
});

it('refuses a configured first guard that cannot be packaged or found locally', function () {
    $manifest = namedEncryptedVaporManifest();
    $policy = encryptedPolicy();
    $policy['guard_command'] = 'php bootstrap/does-not-exist.php {environment}';
    $manifest['environments']['production']['build'][0] = 'php bootstrap/does-not-exist.php production';
    expect((new Runner)->passes((new VaporManifest($manifest, 'production', [], $policy, fixtureRoot()))->run()))->toBeFalse();
    $policy = encryptedPolicy();
    $manifest['environments']['production']['build'][0] = 'php bootstrap/check-vapor-encrypted-environment.php production';
    $manifest['ignore'][] = 'bootstrap';
    expect((new Runner)->passes((new VaporManifest($manifest, 'production', [], $policy, fixtureRoot()))->run()))->toBeFalse();
    $manifest['ignore'][array_key_last($manifest['ignore'])] = 'bootstrap/*.php';
    expect((new Runner)->passes((new VaporManifest($manifest, 'production', [], $policy, fixtureRoot()))->run()))->toBeFalse();
});
