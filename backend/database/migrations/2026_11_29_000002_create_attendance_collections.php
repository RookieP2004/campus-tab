<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 4: attendance. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $c): void {
            $c->index(['tenant_id', 'section_id', 'date', 'period'], 'tenant_section_date_period_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'date']);
            $c->index(['tenant_id', 'year_id', 'section_id']);
        });

        Schema::create('staff_attendance', function (Blueprint $c): void {
            $c->index(['tenant_id', 'date', 'staff_id'], 'tenant_date_staff_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'staff_id', 'date']);
        });

        Schema::create('calendar_events', function (Blueprint $c): void {
            $c->index(['tenant_id', 'date']);
            $c->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['calendar_events', 'staff_attendance', 'attendance_days'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
