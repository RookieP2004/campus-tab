<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * The year-end wizard's "leave this balance in the closed year" choices
 * (fees, fix 1 of session 5).
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::create('fee_year_end_decisions', fn (Blueprint $c) => $c->index(['tenant_id', 'year_id', 'student_id']));
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_year_end_decisions');
    }
};
