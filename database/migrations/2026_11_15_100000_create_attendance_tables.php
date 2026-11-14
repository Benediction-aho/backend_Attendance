<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 – attendance (manual check-in). All instants are timestamptz (server time, Africa/Accra);
 * the work date is computed by the server. One attendance per user per work date (FIABILITE B1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Days off / remote days per contract, versioned by effective date so the past is never rewritten.
        Schema::create('work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from');
            $table->jsonb('days_off');      // ISO weekdays 1..7
            $table->jsonb('remote_days');   // ISO weekdays 1..7
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('set_by_employee')->default(false);
            $table->timestampsTz();
            $table->unique(['user_id', 'effective_from']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['user_id', 'start_date', 'end_date']);
        });
        DB::statement('ALTER TABLE leaves ADD CONSTRAINT leaves_dates_check CHECK (end_date >= start_date)');

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('day_status', 20);           // snapshot: working, remote, day_off, holiday, leave, non_working
            $table->string('check_in_type', 20);        // gps, mission, remote, admin_on_behalf
            $table->timestampTz('check_in_at');
            $table->decimal('check_in_lat', 9, 6)->nullable();
            $table->decimal('check_in_lng', 9, 6)->nullable();
            $table->decimal('check_in_accuracy_m', 8, 2)->nullable();
            $table->decimal('check_in_distance_m', 10, 2)->nullable();
            $table->boolean('faceid_verified')->default(false);
            $table->boolean('is_late')->default(false);
            $table->string('late_after', 5);            // snapshot of the rule used (HH:MM)
            $table->string('reason', 500)->nullable();  // mission reason or admin fallback reason
            $table->string('mission_status', 10)->nullable(); // pending, approved, rejected
            $table->foreignId('mission_validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('mission_validated_at')->nullable();
            $table->string('mission_note', 500)->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('check_out_at')->nullable();
            $table->string('check_out_type', 20)->nullable(); // manual, auto_midnight
            $table->decimal('check_out_lat', 9, 6)->nullable();
            $table->decimal('check_out_lng', 9, 6)->nullable();
            $table->decimal('check_out_accuracy_m', 8, 2)->nullable();
            $table->decimal('check_out_distance_m', 10, 2)->nullable();
            $table->boolean('early_leave')->default(false);
            $table->string('early_leave_reason', 500)->nullable();
            $table->string('end_of_day', 5)->nullable(); // snapshot of the rule used (HH:MM)
            $table->timestampsTz();
            $table->unique(['user_id', 'work_date']);
            $table->index(['work_date']);
        });
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_type_check CHECK (check_in_type IN ('gps','mission','remote','admin_on_behalf'))");
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_checkout_type_check CHECK (check_out_type IS NULL OR check_out_type IN ('manual','auto_midnight'))");
        DB::statement("ALTER TABLE attendances ADD CONSTRAINT attendances_mission_check CHECK ((check_in_type = 'mission') = (mission_status IS NOT NULL) AND (mission_status IS NULL OR mission_status IN ('pending','approved','rejected')))");
        DB::statement('ALTER TABLE attendances ADD CONSTRAINT attendances_checkout_after_check CHECK (check_out_at IS NULL OR check_out_at >= check_in_at)');
        DB::statement('CREATE INDEX attendances_open_idx ON attendances (work_date) WHERE check_out_at IS NULL');

        // Step 1 of a check-in: position checked (or not needed), waiting for confirmation (Face ID from phase 4).
        Schema::create('checkin_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('type', 20);
            $table->date('work_date');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('challenge_hash', 64)->nullable(); // WebAuthn challenge (phase 4), stored hashed
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
        });

        // Refused check-ins (out of perimeter, low accuracy), for admins and investigations.
        Schema::create('checkin_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->date('work_date');
            $table->string('outcome', 30); // out_of_perimeter, low_accuracy
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->unsignedInteger('radius_m');
            $table->unsignedInteger('max_accuracy_m');
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkin_attempts');
        Schema::dropIfExists('checkin_intents');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('leaves');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('work_schedules');
    }
};
