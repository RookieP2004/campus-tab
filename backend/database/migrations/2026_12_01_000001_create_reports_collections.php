<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 7: report cards and year-end. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $c): void {
            $c->index(['tenant_id', 'name']);
        });

        Schema::create('report_cards', function (Blueprint $c): void {
            // One document per version: a student's card for a year is (student, year, version).
            $c->index(['tenant_id', 'student_id', 'year_id', 'version'], 'tenant_student_year_version_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'section_id', 'year_id', 'status']);
            // The public QR page looks a card up by the hash of its random code.
            $c->index(['tenant_id', 'verify_hash'], 'tenant_verify_hash', null, ['sparse' => true]);
        });

        Schema::create('report_card_runs', function (Blueprint $c): void {
            $c->index(['tenant_id', 'section_id', 'created_at']);
            $c->index(['tenant_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['report_card_runs', 'report_cards', 'report_templates'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
