<?php

use Illuminate\Support\Facades\DB;
use MongoDB\Database;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class);

/*
| Decision 0015: the webhook's one query without tenant_id (provider and
| order id) is served by a unique index, so it never scans gateway_orders.
*/

it('serves the webhook lookup from the unique (provider, order_id) index', function () {
    /** @var Database $db */
    $db = DB::connection('mongodb')->getDatabase();

    $index = collect(iterator_to_array($db->selectCollection('gateway_orders')->listIndexes()))
        ->first(fn ($i) => $i->getName() === 'provider_order_unique');
    expect($index)->not->toBeNull()
        ->and($index->getKey())->toBe(['provider' => 1, 'order_id' => 1])
        ->and($index->isUnique())->toBeTrue();

    $plan = $db->command([
        'explain' => ['find' => 'gateway_orders', 'filter' => ['provider' => 'fake', 'order_id' => 'order_x'], 'projection' => ['tenant_id' => 1]],
        'verbosity' => 'queryPlanner',
    ])->toArray()[0];
    $json = json_encode($plan['queryPlanner']['winningPlan']);

    expect($json)->toContain('IXSCAN')->toContain('provider_order_unique')->not->toContain('COLLSCAN');
});
