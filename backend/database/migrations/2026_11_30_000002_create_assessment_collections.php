<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 6: tests and marks. Every index starts with tenant_id.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('assessment_schemes', function (Blueprint $c): void {
            $c->index(['tenant_id', 'year_id']);
        });

        Schema::create('assessments', function (Blueprint $c): void {
            $c->index(['tenant_id', 'year_id', 'from']);
        });

        Schema::create('papers', function (Blueprint $c): void {
            $c->index(['tenant_id', 'assessment_id', 'section_id', 'subject_id'], 'tenant_assessment_section_subject_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'year_id', 'status']);
            $c->index(['tenant_id', 'marks.student_id', 'published_at']);
        });

        Schema::create('correction_requests', function (Blueprint $c): void {
            $c->index(['tenant_id', 'status', 'created_at']);
            $c->index(['tenant_id', 'paper_id', 'student_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['correction_requests', 'papers', 'assessments', 'assessment_schemes'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
