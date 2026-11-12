<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 150);
            $table->timestampsTz();
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['user_id', 'permission_id']);
        });

        // One row per value version: the value applies from effective_from onwards.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);
            $table->jsonb('value');
            $table->timestampTz('effective_from');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['key', 'effective_from']);
        });

        Schema::create('setting_history', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->index();
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value');
            $table->timestampTz('effective_from');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at')->useCurrent();
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sha256 of the client-generated device identifier (never stored in clear).
            $table->string('identifier_hash', 64);
            $table->string('name', 120)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->boolean('is_pwa')->default(false);
            $table->string('last_ip', 45)->nullable();
            $table->timestampTz('first_login_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'identifier_hash']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('name')->constrained('devices')->cascadeOnDelete();
        });

        // Lockout state per bucket: a known device, the shared "unknown devices" bucket of an account, or an IP.
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('account_key', 64)->index(); // sha256(lower(email)), also for unknown emails
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 20); // device | unknown_device
            $table->string('bucket_key', 64); // device identifier hash, or "unknown"
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('series')->default(0); // completed series of failures
            $table->timestampTz('blocked_until')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('last_failed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['account_key', 'scope', 'bucket_key']);
        });

        // One-time codes and links (all hashed): super admin login codes, recovery links, email-change links.
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('code_hash', 255);
            $table->string('lookup_hash', 64)->nullable()->unique(); // sha256 of link tokens, for lookup
            $table->jsonb('meta')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'purpose']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // account concerned
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete(); // who did it (null = system)
            $table->string('type', 20); // action | error | security | automatic
            $table->string('level', 20); // info | warning | error | critical
            $table->string('event', 80);
            $table->string('description', 500);
            $table->jsonb('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'level', 'created_at']);
            $table->index('created_at');
        });

        // Append-only: no UPDATE; DELETE only by the retention purge (session flag).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION activity_logs_append_only() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND current_setting('attendance.allow_log_purge', true) = 'on' THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'activity_logs is append-only';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER activity_logs_no_update BEFORE UPDATE OR DELETE ON activity_logs
    FOR EACH ROW EXECUTE FUNCTION activity_logs_append_only();
SQL);

        Schema::create('scheduled_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 80);
            $table->string('run_key', 150)->unique();
            $table->string('status', 20)->default('running'); // running | succeeded | failed
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->index(['task', 'scheduled_for']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('scheduled_runs');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_logs_no_update ON activity_logs; DROP FUNCTION IF EXISTS activity_logs_append_only();');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('login_attempts');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('devices');
        Schema::dropIfExists('setting_history');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('permissions');
    }
};
