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

    'autobg_sweep' => [
        // The auto.bg API caps pagination at 100 pages (2000 adverts). This is
        // both the hard page limit and the truncation signal: a slug whose
        // lastpage reports 100 is truncated and must be split by model.
        'page_cap' => 100,

        // Pause between page requests (ms) to bound the sustained request rate.
        'pause_ms' => 150,

        // Listings loaded per reconciliation chunk.
        'chunk_size' => 500,

        // Per-request TCP connect and total timeouts (seconds). A timed-out
        // page surfaces as ok=false and simply ends that segment's paging.
        'connect_timeout' => 10,
        'request_timeout' => 20,
    ],

    'mobilebg_sweep' => [
        // mobile.bg caps any result set at ~151 pages. Treated as the truncation
        // signal: a segment returning cards up to this page is assumed to have
        // more and is split by model.
        'page_cap' => 150,

        // Pause between page requests (ms) to bound the sustained request rate.
        'pause_ms' => 300,

        // Per-request TCP connect and total timeouts (seconds).
        'connect_timeout' => 10,
        'request_timeout' => 20,
    ],
];
