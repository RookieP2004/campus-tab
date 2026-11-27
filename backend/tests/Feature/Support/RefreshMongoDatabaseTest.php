<?php

use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class);

/*
| These two tests run in order. The first writes a document; the second
| proves the helper dropped it before the next test started.
*/

it('can write to the test database', function () {
    DB::table('reset_probe')->insert(['note' => 'left behind on purpose']);

    expect(DB::table('reset_probe')->count())->toBe(1);
});

it('starts every test with an empty database', function () {
    expect(DB::table('reset_probe')->count())->toBe(0);
});

it('uses a database whose name ends in _test', function () {
    expect(DB::connection()->getDatabaseName())->toEndWith('_test');
});
