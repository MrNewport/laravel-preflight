<?php

namespace MrNewport\LaravelPreflight;

/** Static archive policy. The build guard remains responsible for inspecting actual files. */
final class VaporEnvironmentFiles
{
    private const REQUIRED_PLAINTEXT = ['.env', '.env.backup', '.env.local',
        '.env.production', '.env.staging', '.env.testing'];

    public static function check(mixed $ignore, mixed $settings, string $environment, mixed $policy, ?string $manifestDirectory = null): Result
    {
        if ($policy === null) {
            $valid = is_array($ignore) && (in_array('.env*', $ignore, true)
                || (in_array('.env', $ignore, true) && in_array('.env.*', $ignore, true)));

            return Result::assertion('vapor.environment_files', $valid,
                'Explicitly exclude .env and .env.* files from the deployment archive.');
        }

        if (! is_array($policy) || count($policy) !== 4
            || array_diff(['mode', 'plaintext_exclusions', 'encrypted_files', 'guard_command'], array_keys($policy)) !== []
            || $policy['mode'] !== 'named-encrypted' || ! is_array($ignore)
            || ! is_array($settings) || ! is_array($policy['plaintext_exclusions'])
            || ! is_array($policy['encrypted_files']) || ! is_string($policy['guard_command'])) {
            return self::failure();
        }
        $plaintext = $policy['plaintext_exclusions'];
        $encrypted = $policy['encrypted_files'];
        if ($plaintext === [] || count(array_filter($plaintext, 'is_string')) !== count($plaintext)
            || count($plaintext) !== count(array_unique($plaintext))
            || array_diff(self::REQUIRED_PLAINTEXT, $plaintext) !== []
            || ! in_array('.env.'.$environment, $plaintext, true)
            || count($encrypted) < 1 || ! isset($encrypted[$environment])
            || $encrypted[$environment] !== '.env.'.$environment.'.encrypted') {
            return self::failure();
        }
        foreach ($plaintext as $name) {
            if (! is_string($name) || ! preg_match('/\A\.env(?:\.[a-z][a-z0-9_-]*)?\z/D', $name)
                || ! in_array($name, $ignore, true)) {
                return self::failure();
            }
        }
        foreach ($encrypted as $selectedEnvironment => $name) {
            if (! is_string($selectedEnvironment)
                || ! preg_match('/\A[a-z][a-z0-9_-]*\z/D', $selectedEnvironment)
                || $name !== '.env.'.$selectedEnvironment.'.encrypted') {
                return self::failure();
            }
            foreach ($ignore as $pattern) {
                if (! is_string($pattern) || fnmatch($pattern, $name)) {
                    return self::failure();
                }
            }
        }
        $expected = str_replace('{environment}', $environment, $policy['guard_command']);
        $build = $settings['build'] ?? null;
        $guard = $policy['guard_command'];
        $matches = [];
        if (preg_match('/\Aphp ([a-zA-Z0-9_.\/-]+\.php) \{environment\}\z/D', $guard, $matches) !== 1
            || str_contains($matches[1], '..') || str_starts_with($matches[1], '/')
            || ! self::packagedGuard($manifestDirectory, $matches[1], $ignore)) {
            return self::failure();
        }

        return Result::assertion('vapor.environment_files',
            is_array($build) && ($build[0] ?? null) === $expected,
            'Exclude named plaintext files, preserve encrypted artifacts, and run the configured build guard first.');
    }

    private static function packagedGuard(?string $manifestDirectory, string $script, array $ignore): bool
    {
        if ($manifestDirectory === null || ($root = realpath($manifestDirectory)) === false) {
            return false;
        }
        $path = $root.'/'.$script;
        if (! is_file($path) || is_link($path) || ($real = realpath($path)) === false
            || ! str_starts_with($real, $root.'/')) {
            return false;
        }
        $parts = explode('/', $script);
        $ancestors = [];
        for ($index = 1; $index < count($parts); $index++) {
            $ancestors[] = implode('/', array_slice($parts, 0, $index));
        }
        foreach ($ignore as $pattern) {
            if (! is_string($pattern)) {
                return false;
            }
            $pattern = trim($pattern, '/');
            foreach ([$script, ...$ancestors] as $candidate) {
                if (fnmatch($pattern, $candidate)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function failure(): Result
    {
        return new Result('vapor.environment_files', 'fail',
            'The configured environment-file policy is invalid.');
    }
}
