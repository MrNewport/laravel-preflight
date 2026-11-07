# Laravel Preflight handbook

Preflight runs read-only release checks through a small `Check`/`Result` contract. This handbook describes the 1.1 API in `src/`, its configuration and its tests. The package does not deploy a site, contact AWS, repair configuration, test database connectivity or guarantee an AWS spending cap.

## Install and choose an execution context

Requirements are PHP 8.2+ with Laravel 12, or the framework's PHP floor with Laravel 13. The package uses Illuminate Support/Console and Symfony YAML. Composer discovery registers the provider and `preflight:check` command.

```sh
composer config repositories.preflight vcs https://github.com/MrNewport/laravel-preflight
composer require mrnewport/laravel-preflight:^1.1
php artisan vendor:publish --tag=preflight-config
```

The explicit VCS source works when a release is not indexed in the application's registry. Publishing configuration is optional. Existing application rules should remain in the host and be composed with these checks.

Choose a profile according to what is available where the command runs:

| Profile | Reads | Appropriate context |
| --- | --- | --- |
| `vapor` | One local YAML manifest plus configured custom checks | Before packaging, with no cloud access |
| `production` | The bootstrapped Laravel application's current configuration plus custom checks | The intended production runtime/configuration |
| `all` (default) | Both of the above | A context with the intended configuration and manifest |

An Artisan invocation boots its host application. A passing build-machine production check describes that machine's loaded configuration, not a remote environment it never inspected. Config caching and secret injection must be handled by the deployment process.

## Command reference

```sh
php artisan preflight:check --profile=vapor --manifest=vapor.yml --environment=production --json --strict
php artisan preflight:check --profile=production --json
```

| Option | Default | Behavior |
| --- | --- | --- |
| `--profile` | `all` | Exactly `all`, `production` or `vapor` |
| `--manifest` | Application `vapor.yml` | Readable local file; URL/stream-wrapper paths are refused |
| `--environment` | `production` | Selected manifest environment |
| `--json` | Off | Print machine-readable report |
| `--strict` | Off | Treat warnings as a failing result |

Exit 0 means the selected checks pass under the chosen strictness. Exit 1 means a failing result, a strict warning or invalid input. Invalid profiles and unreadable/non-array YAML become report failures. The command does not change the manifest.

```json
{
  "passed": false,
  "results": [
    {"name": "app.debug", "status": "fail", "message": "Disable application debug mode."}
  ]
}
```

Without `--json`, each line is `PASS`, `WARN` or `FAIL`, followed by its name and message. Messages are diagnostic text; do not put secrets into custom messages.

## Production configuration checks

`ProductionConfiguration` receives an `Illuminate\Contracts\Config\Repository`. It checks the following values without returning their contents:

| Result | Required condition |
| --- | --- |
| `app.debug` | Exactly `false` |
| `app.key` | String of the required decoded length for the selected cipher |
| `app.url` | HTTPS URL with a host and no embedded username/password |
| `session.secure` | Exactly `true` |
| `session.http_only` | Exactly `true` |
| `cache.shared` | Configured shared cache driver when shared state is required |
| `session.shared` | Database sessions or an approved shared cache-backed session driver when required |

The supported cipher lengths are 16 bytes for AES-128 CBC/GCM and 32 bytes for AES-256 CBC/GCM. A `base64:` key is decoded strictly before checking. This verifies shape and length, not secrecy or rotation policy.

The built-in shared drivers are database, Redis, DynamoDB and Memcached. Cache store aliases are resolved through `cache.stores.{name}.driver`; session stores use `session.store` when configured. Custom storage drivers require an application-specific check.

Set `preflight.require_shared_state` to `false` only when the application's design deliberately has no shared-state requirement. For example, a stateless publication using encrypted cookie sessions may choose this. That setting does not make an array cache a durable distributed rate limiter.

## Vapor manifest checks

`VaporManifest` accepts an already parsed manifest array; it does not contact Vapor. The selected environment must exist and be an array.

```yaml
ignore:
  - .env
  - .env.*
environments:
  production:
    domain: example.com
    memory: 512
    concurrency: 5
    cli-concurrency: 2
    warm: 0
```

Use the singular `domain` key for both one hostname and a list. Any environment containing `domains` fails `vapor.domain_key`, including an unselected staging environment. Selected domains must be valid hostnames containing a dot. These checks do not verify DNS, TLS, domain ownership or the provider binding.

Default limits are:

```php
'vapor_limits' => [
    'concurrency' => 5,
    'cli-concurrency' => 2,
    'warm' => 0,
    'memory' => 512,
],
```

Each listed setting must be an explicit integer. Values must be at least 1, except `warm`, which may be 0, and must not exceed the configured maximum. Quoted YAML numbers fail. The host can supply different limits for a measured workload. These are admission rules, not a prediction of traffic charges or a billing cap.

## Environment-file archive policy

With `preflight.vapor_environment_files = null`, the original policy requires either `.env*` or both `.env` and `.env.*` in `ignore`.

Version 1.1 additionally supports an explicit named-encrypted policy:

```php
'vapor_environment_files' => [
    'mode' => 'named-encrypted',
    'plaintext_exclusions' => [
        '.env', '.env.backup', '.env.local',
        '.env.production', '.env.staging', '.env.testing',
    ],
    'encrypted_files' => [
        'production' => '.env.production.encrypted',
    ],
    'guard_command' => 'php bootstrap/check-vapor-encrypted-environment.php {environment}',
],
```

For this policy:

1. The policy must contain exactly its four documented keys.
2. Required plaintext names and the selected environment's plaintext file must be explicitly ignored.
3. Encrypted names must exactly match `.env.{environment}.encrypted` and remain outside every ignore pattern.
4. The trusted guard path must be relative, end in `.php`, contain no `..`, resolve inside the manifest directory, be a regular non-symlink file and remain packaged.
5. The first selected build command must exactly match the guard command after `{environment}` substitution.

The check reads file metadata to verify the guard is packaged. It does not run that script, decrypt a file or inspect the actual archive. The application owns the guard's implementation, failure behavior, encryption-key delivery and final archive inspection. `tests/Fixtures/` contains an executable-policy example for study, not a universal production script.

The command supplies the real manifest directory automatically. Direct PHP callers using this policy must pass it themselves.

## Compose application checks

```php
use MrNewport\LaravelPreflight\Check;
use MrNewport\LaravelPreflight\Result;

final class AssetsReady implements Check
{
    public function run(): array
    {
        return [Result::assertion(
            'assets.built',
            is_file(public_path('build/manifest.json')),
            'Build frontend assets before packaging.',
        )];
    }
}
```

```php
// config/preflight.php
'checks' => [AssetsReady::class],
```

Laravel resolves these classes through its container. They run for every valid profile. Unresolvable classes and values that do not implement `Check` produce a generic `custom_check` failure. Keep custom checks read-only; the shared interface does not prevent a badly written implementation from mutating state.

`Result` accepts only `pass`, `warn` or `fail`; it implements `JsonSerializable`. `Result::assertion($name, $condition, $failureMessage)` returns `Passed.` on success and the supplied message on failure. The package does not sanitize a message intentionally placed in a successful result.

## Use the PHP API without the command

```php
use MrNewport\LaravelPreflight\ProductionConfiguration;
use MrNewport\LaravelPreflight\Runner;
use MrNewport\LaravelPreflight\VaporManifest;

$runner = new Runner;
$results = $runner->run([
    new ProductionConfiguration($config),
    new VaporManifest($manifest, 'production', $limits),
    new AssetsReady,
]);

if (! $runner->passes($results, strict: true)) {
    throw new RuntimeException('Release checks failed.');
}
```

`Runner::run()` collects results in check order. Each check must return only `Result` instances. An exception or invalid result makes that check fail with a generic message because exception details can contain credentials. Other checks can still run. An empty report passes, so callers must supply the checks they intend to require.

## Application recipes

**A static publication:** run the Vapor profile before packaging. Add publication-specific checks for built assets, required content and approved domains. Set shared-state policy deliberately; retain the runtime's own secure-cookie policy.

**A distributed authenticated app:** run production checks with the intended session/cache configuration. Add checks for exact database identity, queue/outbox settings and mandatory storage bindings. A recognized driver name does not prove it is reachable or configured correctly.

**A named encrypted deployment:** adopt the explicit environment-file policy, supply the guard first, and independently inspect the built archive. A source `.env*` wildcard would also omit encrypted files and therefore conflicts with this mode.

## Testing and maintenance

```sh
composer install
composer test
```

The package tests command discovery, JSON/exit codes, profiles, strict warnings, shared storage rules, exception redaction, malformed manifests, integer limits, domain rules, environment-file policies and file immutability. CI includes supported Laravel/PHP combinations and a clean application installation. Consult `.github/workflows/tests.yml` for the current matrix; this handbook does not claim a live provider deployment test.

When extending a repeated rule, implement a tested package `Check` or configurable policy. Preserve application-specific ownership, workflow and deployment identity checks in the host. See [feature inventory](FEATURES.md) and [configuration](../config/preflight.php).
