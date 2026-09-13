# Laravel Preflight

Read-only deployment checks for Laravel and Vapor. Catch exposed debug mode, invalid application keys, local session/cache storage, ignored domain configuration, and missing concurrency limits before a release. JSON output and exit codes make the same checks usable in local scripts and CI.

Supports Laravel 12 on PHP 8.2+ and Laravel 13 on PHP 8.3+. No cloud API calls, new infrastructure, database writes, or automatic fixes.

```sh
composer require mrnewport/laravel-preflight:^1.0
php artisan preflight:check --profile=vapor --manifest=vapor.yml --json
php artisan preflight:check --profile=production --json
php artisan preflight:check --strict
```

Until Packagist indexes the package, add the GitHub repository as a Composer VCS source:

```sh
composer config repositories.preflight vcs https://github.com/MrNewport/laravel-preflight
```

The `vapor` profile reads a local manifest and can run during a local build. The `production` profile checks the bootstrapped application's current configuration; run it in the production runtime or with the intended release configuration loaded. The default `all` profile checks both. It does not test database connectivity or simulate Vapor's complete archive builder.

Production checks cover debug mode, key length/cipher, HTTPS URL, secure/HttpOnly cookies, shared cache, and shared server-side sessions. Key contents and connection values never appear in the bundled check results. Custom check exceptions produce a generic failure because exception messages can contain secrets.

Vapor checks require the singular `domain` key, including for a list of hostnames; reject the unsupported plural key in any environment; require explicit bounded memory/concurrency/warm settings; and require environment-file exclusions. Defaults reflect a small portfolio: memory 512 MB, web concurrency 5, CLI concurrency 2, warm 0.

These are configurable deployment policies, **not an AWS spending cap or cost forecast**. Database, storage, requests, traffic, and other AWS resources still accrue charges. Keep AWS budgets and application-specific deployment tests.

```sh
php artisan vendor:publish --tag=preflight-config
```

Adjust `preflight.vapor_limits` for the project. Set `require_shared_state` to false for deployments that intentionally use a single persistent server. The bundled checks do not attempt to enumerate every possible custom session or cache driver.

## Add application checks

```php
use MrNewport\LaravelPreflight\{Check, Result};

final class AssetsReady implements Check
{
    public function run(): array
    {
        return [Result::assertion(
            'assets.built',
            is_file(public_path('build/manifest.json')),
            'Build the frontend assets before deployment.',
        )];
    }
}
```

Add the class to `preflight.checks`. Laravel resolves checks through its container. Custom checks run for every profile; keep them read-only and never include secret values in messages. Results use `pass`, `warn`, or `fail`. A failure exits 1; `--strict` makes warnings exit 1 too. JSON is shaped as `{"passed":true,"results":[{"name":"...","status":"pass","message":"Passed."}]}`.

You can also use `Runner`, `ProductionConfiguration`, and `VaporManifest` directly from existing deployment scripts. Preserve project-specific checks such as build artifacts, database server identity, approved domains, and runtime settings.

`composer test` covers provider/command registration, JSON/exit codes, manifest boundaries, policy limits, shared state, malformed input, exception redaction, and file immutability. CI installs the package in a fresh Laravel 13 application without test dependencies.
