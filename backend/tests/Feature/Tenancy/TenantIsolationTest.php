<?php

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\CrossTenantWrite;
use App\Support\Tenancy\MissingTenantContext;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantQueryGuard;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesSchools;
use Tests\Concerns\RefreshMongoDatabase;

uses(RefreshMongoDatabase::class, CreatesSchools::class);

/*
| Item 5: BelongsToTenant (global scope and creating hook) and the tenant
| query guard. Every test in the suite also fails if the guard records a
| school-data query without tenant_id (Tests\TestCase).
*/

beforeEach(function () {
    $this->a = $this->activeSchool('ALPHA-BPL');
    $this->b = $this->activeSchool('BETA-IDR');
    $this->context = app(TenantContext::class);
});

it('reads only the current school', function () {
    $this->schoolUser($this->a['tenant'], Role::Clerk, email: 'same@school.test');
    $this->schoolUser($this->b['tenant'], Role::Clerk, email: 'same@school.test');

    $seenByA = $this->context->run($this->a['tenant']->id, fn () => User::query()->get());
    $seenByB = $this->context->run($this->b['tenant']->id, fn () => User::query()->where('email', 'same@school.test')->get());

    expect($seenByA)->toHaveCount(2) // the Chief and the clerk of ALPHA
        ->and($seenByA->pluck('tenant_id')->unique()->all())->toBe([$this->a['tenant']->id])
        ->and($seenByB)->toHaveCount(1)
        ->and($seenByB->first()->tenant_id)->toBe($this->b['tenant']->id);
});

it('cannot find another school\'s record by its id', function () {
    $chiefOfB = $this->b['chief'];

    $found = $this->context->run($this->a['tenant']->id, fn () => User::query()->find($chiefOfB->getKey()));

    expect($found)->toBeNull();
});

it('throws instead of reading every school when no tenant is set', function () {
    User::query()->get();
})->throws(MissingTenantContext::class);

it('fills tenant_id from the context on create', function () {
    $user = $this->context->run($this->a['tenant']->id, fn () => User::create(['name' => 'New Clerk', 'email' => 'new@alpha.test', 'password' => 'x', 'roles' => ['clerk']]));

    expect($user->tenant_id)->toBe($this->a['tenant']->id);
});

it('refuses to create a record for another school', function () {
    $this->context->run($this->a['tenant']->id, fn () => User::create([
        'tenant_id' => $this->b['tenant']->id,
        'name' => 'Sneaky',
        'email' => 'sneaky@beta.test',
        'password' => 'x',
    ]));
})->throws(CrossTenantWrite::class);

it('refuses to create a school record with no tenant set', function () {
    User::create(['name' => 'Orphan', 'email' => 'orphan@nowhere.test', 'password' => 'x']);
})->throws(MissingTenantContext::class);

it('refuses to update another school\'s record', function () {
    $chiefOfB = $this->b['chief'];

    $this->context->run($this->a['tenant']->id, function () use ($chiefOfB) {
        $chiefOfB->name = 'Changed by ALPHA';
        $chiefOfB->save();
    });
})->throws(CrossTenantWrite::class);

it('refuses to move a record to another school', function () {
    $chiefOfA = $this->a['chief'];

    $this->context->run($this->a['tenant']->id, function () use ($chiefOfA) {
        $chiefOfA->tenant_id = $this->b['tenant']->id;
        $chiefOfA->save();
    });
})->throws(CrossTenantWrite::class);

it('refuses to delete another school\'s record', function () {
    $chiefOfB = $this->b['chief'];

    $this->context->run($this->a['tenant']->id, fn () => $chiefOfB->delete());
})->throws(CrossTenantWrite::class);

it('blocks a cross-school bulk update through the scope', function () {
    $changed = $this->context->run($this->a['tenant']->id, fn () => User::query()->where('_id', $this->b['chief']->getKey())->update(['name' => 'Changed']));

    expect($changed)->toBe(0)
        ->and($this->context->run($this->b['tenant']->id, fn () => User::query()->find($this->b['chief']->getKey())->name))->not->toBe('Changed');
});

it('sends tenant_id with saves and soft deletes of a school record', function () {
    $clerk = $this->schoolUser($this->a['tenant'], Role::Clerk);

    $this->context->run($this->a['tenant']->id, function () use ($clerk) {
        $clerk->name = 'Renamed';
        $clerk->save();
        $clerk->delete();
    });

    // assertPostConditions() fails this test if any of those commands lacked tenant_id.
    expect(TenantQueryGuard::violations())->toBe([]);
});

describe('the tenant query guard', function () {
    it('records a raw query that skips the tenant filter', function () {
        DB::table('users')->where('email', 'someone@alpha.test')->first();

        expect(TenantQueryGuard::violations())->toHaveCount(1)
            ->and(TenantQueryGuard::violations()[0])->toMatchArray(['collection' => 'users', 'command' => 'find']);

        TenantQueryGuard::flush();
    });

    it('records raw updates, deletes, inserts and aggregates without tenant_id', function () {
        DB::table('users')->where('email', 'x@alpha.test')->update(['name' => 'y']);
        DB::table('users')->where('email', 'x@alpha.test')->delete();
        DB::table('users')->insert(['name' => 'no tenant']);
        DB::table('users')->count();

        $commands = array_column(TenantQueryGuard::violations(), 'command');

        expect($commands)->toContain('update', 'delete', 'insert', 'aggregate');

        TenantQueryGuard::flush();
    });

    it('accepts raw queries that do carry tenant_id', function () {
        DB::table('users')->where('tenant_id', $this->a['tenant']->id)->where('email', 'x@alpha.test')->first();

        expect(TenantQueryGuard::violations())->toBe([]);
    });

    it('treats a collection it does not know as school data (fail closed)', function () {
        DB::table('brand_new_collection')->insert(['note' => 'no tenant']);

        expect(TenantQueryGuard::violations())->toHaveCount(1);

        TenantQueryGuard::flush();
    });

    it('ignores platform collections, including the token collection', function () {
        DB::table('tenants')->where('code', 'ALPHA-BPL')->first();
        DB::table('licences')->where('status', 'active')->get();
        DB::table('personal_access_tokens')->where('name', 'test')->get();

        expect(config('tenancy.platform_collections'))->toContain('personal_access_tokens')
            ->and(TenantQueryGuard::violations())->toBe([]);
    });

    it('allows queries inside a documented bypass', function () {
        $this->context->bypass('test: documented exception', fn () => DB::table('users')->where('email', 'x@alpha.test')->first());

        expect(TenantQueryGuard::violations())->toBe([]);
    });
});
