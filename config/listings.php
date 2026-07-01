<?php

return [
    'cleanup' => [
        // Listings probed per run (oldest checked_at first).
        'batch_limit' => 5000,

        // Max concurrent HTTP probes in flight per Http::pool chunk.
        'pool_concurrency' => 25,

        // Pause between pool chunks (ms) to bound sustained per-host request rate.
        'pool_pause_ms' => 250,

        // Per-probe TCP connect timeout (seconds). Without it a host that never
        // responds hangs the whole pool chunk indefinitely.
        'pool_connect_timeout' => 10,

        // Per-probe total request timeout (seconds). A timed-out probe surfaces
        // as a ConnectionException, classified 'unknown' and retried next run.
        'pool_timeout' => 20,
    ],
];
