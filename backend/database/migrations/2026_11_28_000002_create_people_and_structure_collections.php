<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 2: academic structure, people, customisation and imports. Every
 * index starts with tenant_id ("Keeping schools apart").
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $c): void {
            $c->index(['tenant_id', 'status']);
            $c->index(['tenant_id', 'name'], 'tenant_year_name');
        });

        Schema::create('classes', function (Blueprint $c): void {
            $c->index(['tenant_id', 'year_id', 'order']);
        });

        Schema::create('sections', function (Blueprint $c): void {
            $c->index(['tenant_id', 'year_id', 'class_id']);
            $c->index(['tenant_id', 'class_teacher_id']);
        });

        Schema::create('subjects', function (Blueprint $c): void {
            $c->index(['tenant_id', 'code'], 'tenant_subject_code_unique', null, ['unique' => true]);
        });

        Schema::create('teaching_assignments', function (Blueprint $c): void {
            $c->index(['tenant_id', 'teacher_id', 'year_id']);
            $c->index(['tenant_id', 'section_id', 'subject_id']);
        });

        Schema::create('students', function (Blueprint $c): void {
            $c->index(['tenant_id', 'admission_no'], 'tenant_admission_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'name', 'dob']);
            $c->index(['tenant_id', 'status']);
            $c->index(['tenant_id', 'user_id']);
        });

        Schema::create('enrolments', function (Blueprint $c): void {
            $c->index(['tenant_id', 'section_id', 'roll_no']);
            $c->index(['tenant_id', 'student_id', 'year_id']);
        });

        Schema::create('guardians', function (Blueprint $c): void {
            $c->index(['tenant_id', 'phone']);
            $c->index(['tenant_id', 'user_id']);
            $c->index(['tenant_id', 'student_ids']);
        });

        Schema::create('staff', function (Blueprint $c): void {
            $c->index(['tenant_id', 'employee_code'], 'tenant_employee_code_unique', null, ['unique' => true]);
            $c->index(['tenant_id', 'user_id']);
            $c->index(['tenant_id', 'status']);
        });

        Schema::create('consents', function (Blueprint $c): void {
            $c->index(['tenant_id', 'student_id', 'purpose']);
        });

        Schema::create('custom_fields', function (Blueprint $c): void {
            $c->index(['tenant_id', 'entity', 'scope.level', 'scope.ref']);
            $c->index(['tenant_id', 'entity', 'key']);
        });

        Schema::create('import_batches', function (Blueprint $c): void {
            $c->index(['tenant_id', 'created_at']);
        });

        // Student login names (and any other login name) are unique in a school.
        Schema::table('users', function (Blueprint $c): void {
            $c->index(['tenant_id', 'username'], 'tenant_username_unique', null, [
                'unique' => true,
                'partialFilterExpression' => ['username' => ['$type' => 'string']],
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $c) => $c->dropIndex('tenant_username_unique'));

        foreach (['import_batches', 'custom_fields', 'consents', 'staff', 'guardians', 'enrolments', 'students', 'teaching_assignments', 'subjects', 'sections', 'classes', 'academic_years'] as $collection) {
            Schema::dropIfExists($collection);
        }
    }
};
