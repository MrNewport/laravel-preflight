# Preflight feature inventory

This inventory describes implemented package behavior, with source and test entry points. It does not imply a cloud deployment was exercised.

| Capability | Public API / evidence | Boundary |
| --- | --- | --- |
| Laravel command discovery | `PreflightServiceProvider`, `PreflightCommand` | Host Artisan boot still occurs |
| Production configuration review | `ProductionConfiguration::run()` | Shape/policy checks; no connectivity probes |
| Local Vapor manifest review | `VaporManifest::run()` | No Vapor/AWS API calls |
| Domain-key and hostname validation | `VaporManifest` | No DNS, TLS or ownership verification |
| Explicit resource admission limits | `preflight.vapor_limits` | Not a billing cap |
| Original `.env*` exclusion policy | `VaporEnvironmentFiles::check()` with null policy | Does not inspect final archive |
| Named encrypted environment policy | `vapor_environment_files` configuration | Host supplies and executes archive guard |
| Composable custom checks | `Check::run(): array<Result>` | Implementations must remain read-only |
| Structured status values | `Result`, `Result::assertion()` | Host messages must omit secrets |
| Exception redaction and continued aggregation | `Runner::run()` | Detailed local diagnosis belongs to the check owner |
| Strict warning failure | `Runner::passes(..., strict: true)` | Default warnings do not fail |
| JSON report and CLI exit status | `preflight:check --json --strict` | Empty caller-supplied PHP check list passes |
| Tested supported framework installation | `tests/Feature/`, CI workflow | Not a claim of provider end-to-end acceptance |

For installation, complete option/configuration reference and application recipes, read the [handbook](HANDBOOK.md).
