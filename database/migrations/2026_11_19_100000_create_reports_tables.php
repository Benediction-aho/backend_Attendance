<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: tasks, clients, daily report snapshots, weekly PDF submissions (versioned), monthly reports,
 * and per-recipient delivery records for report notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('title', 500);
            $table->string('status', 10)->default('planned');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'work_date']);
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('planned', 'done'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_completed_check CHECK ((status = 'done') = (completed_at IS NOT NULL))");

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->string('name', 200);
            $table->string('service', 200);
            $table->string('service_key', 200);
            $table->unsignedInteger('quantity');
            $table->timestampsTz();
            $table->index(['user_id', 'work_date']);
            $table->index('service_key');
        });
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_quantity_check CHECK (quantity >= 1)');

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('tasks_planned');
            $table->unsignedInteger('tasks_done');
            $table->unsignedInteger('clients_count');
            $table->jsonb('services');
            $table->timestampTz('generated_at');
            $table->timestampTz('recomputed_at')->nullable();
            $table->unique(['user_id', 'work_date']);
        });

        Schema::create('weekly_report_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->date('window_date');
            $table->unsignedInteger('version');
            $table->boolean('is_current')->default(true);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestampTz('submitted_at');
            $table->boolean('is_late');
            $table->timestampTz('forwarded_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'week_start', 'version']);
            $table->index(['week_start', 'is_current']);
        });
        DB::statement('CREATE UNIQUE INDEX weekly_report_submissions_one_current ON weekly_report_submissions (user_id, week_start) WHERE is_current');

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique(); // YYYY-MM of the sending month
            $table->date('period_start');
            $table->date('period_end');
            $table->string('path', 255);
            $table->string('file_name', 100);
            $table->timestampTz('generated_at');
        });

        // Per-recipient delivery of report notifications/emails (weekly close, late forward, monthly).
        Schema::create('report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 50);
            $table->string('ref', 50);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('sent_at');
            $table->unique(['kind', 'ref', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deliveries');
        Schema::dropIfExists('monthly_reports');
        Schema::dropIfExists('weekly_report_submissions');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('tasks');
    }
};
