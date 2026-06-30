<?php

return [
    'cleanup' => [
        // Listings probed per run (oldest checked_at first).
        'batch_limit' => 5000,

        // Max concurrent HTTP probes in flight per Http::pool chunk.
        'pool_concurrency' => 25,
    ],
];
