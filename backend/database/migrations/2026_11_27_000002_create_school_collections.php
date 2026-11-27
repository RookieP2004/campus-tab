<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * School collections created in Phase 1 part A. Every index starts with
 * tenant_id ("Keeping schools apart").
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('users', function (Blueprint $c): void {
            // Partial indexes: a user without an email (or phone) is not a duplicate.
            $c->index(['tenant_id', 'email'], 'tenant_email_unique', null, [
                'unique' => true,
                'partialFilterExpression' => ['email' => ['$type' => 'string']],
            ]);
            $c->index(['tenant_id', 'phone'], 'tenant_phone_unique', null, [
                'unique' => true,
                'partialFilterExpression' => ['phone' => ['$type' => 'string']],
            ]);
            $c->index(['tenant_id', 'roles']);
        });

        Schema::create('school_configs', function (Blueprint $c): void {
            $c->index(['tenant_id', 'version'], 'tenant_version_unique', null, ['unique' => true]);
        });

        Schema::create('support_grants', function (Blueprint $c): void {
            $c->index(['tenant_id', 'expires_at']);
            $c->index(['tenant_id', 'agent']);
        });
    }

    public function down(): void
    {
        foreach (['support_grants', 'school_configs', 'users'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
