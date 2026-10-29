<?php

return [
    'require_shared_state' => true,
    // Project policy, not an AWS spending cap or a complete resource cost estimate.
    'vapor_limits' => ['concurrency' => 5, 'cli-concurrency' => 2, 'warm' => 0, 'memory' => 512],
    // Null preserves the original strict .env* exclusion rule.
    'vapor_environment_files' => null,
    // Container-resolvable Check implementations, appended for every profile.
    'checks' => [],
];
