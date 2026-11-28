<?php

use Tests\TestCase;

/*
| Parallel runs (composer test = pest --parallel): every process of every
| run has its own MongoDB database, cache key prefix and fake-disk folder,
| keyed by "<process>_<run>", so two runs at once (or workers left over from
| a run that timed out) never empty each other's collections or share rate
| limits, idempotency keys or locks.
*/

it('gives each parallel process of each run its own database and cache key space', function () {
    $token = TestCase::parallelToken();
    if ($token === null) {
        expect(config('database.connections.mongodb.database'))->toBe('campus_tab_test');

        return;
    }

    expect($token)->toMatch('/^\d+_\d+$/')
        ->and(explode('_', $token)[1])->toBe((string) posix_getppid())
        ->and(config('database.connections.mongodb.database'))->toBe("campus_tab_{$token}_test")
        ->and(config('cache.prefix'))->toContain($token)
        ->and(config('database.redis.options.prefix'))->toBe("campus-tab-test-{$token}-");
});
