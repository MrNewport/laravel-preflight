<?php

namespace MrNewport\LaravelPreflight;

final class Runner
{
    /** @param iterable<Check> $checks @return list<Result> */
    public function run(iterable $checks): array
    {
        $results = [];
        foreach ($checks as $check) {
            try {
                $batch = $check->run();
                foreach ($batch as $result) {
                    if (!$result instanceof Result) {
                        throw new \UnexpectedValueException('Check returned an invalid result.');
                    }
                }
                array_push($results, ...$batch);
            } catch (\Throwable) {
                // Exception messages may contain connection strings or credentials.
                $results[] = new Result($check::class, 'fail', 'Check could not complete. Inspect this check locally.');
            }
        }
        return $results;
    }

    /** @param list<Result> $results */
    public function passes(array $results, bool $strict = false): bool
    {
        foreach ($results as $result) {
            if ($result->status === 'fail' || ($strict && $result->status === 'warn')) {
                return false;
            }
        }
        return true;
    }
}
