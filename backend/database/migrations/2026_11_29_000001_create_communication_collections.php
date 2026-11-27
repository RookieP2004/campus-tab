<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 3: notices, read receipts, notifications and push devices. Every
 * index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('notices', function (Blueprint $c): void {
            $c->index(['tenant_id', 'audience.type', 'published_at']);
            $c->index(['tenant_id', 'published_at']);
        });

        Schema::create('notice_reads', function (Blueprint $c): void {
            $c->index(['tenant_id', 'notice_id', 'user_id'], 'tenant_notice_user_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'user_id']);
        });

        Schema::create('notifications', function (Blueprint $c): void {
            $c->index(['tenant_id', 'user_id', 'created_at']);
            $c->index(['tenant_id', 'user_id', 'read_at']);
        });

        Schema::create('push_devices', function (Blueprint $c): void {
            $c->index(['tenant_id', 'token_hash'], 'tenant_push_token_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['push_devices', 'notifications', 'notice_reads', 'notices'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
