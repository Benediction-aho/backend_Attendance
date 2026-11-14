<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 – Face ID (WebAuthn passkeys). Only public keys are stored; challenges are single use and short-lived.
 * Also merges the old `faceid_required` setting into `faceid_required_for_checkin`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webauthn_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('credential_id', 1400)->unique(); // base64url
            $table->text('public_key'); // COSE key, base64url
            $table->string('user_handle', 128); // base64url
            $table->uuid('aaguid')->nullable();
            $table->jsonb('transports')->nullable();
            $table->string('attestation_type', 20)->default('none');
            $table->bigInteger('sign_count')->default(0);
            $table->boolean('backup_eligible')->nullable();
            $table->boolean('backup_status')->nullable();
            $table->boolean('uv_initialized')->nullable();
            $table->string('name', 100)->nullable();
            $table->string('status', 10)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoke_reason', 30)->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE webauthn_credentials ADD CONSTRAINT webauthn_credentials_status_check CHECK (status IN ('pending','approved','revoked'))");
        DB::statement('ALTER TABLE webauthn_credentials ADD CONSTRAINT webauthn_credentials_sign_count_check CHECK (sign_count >= 0)');
        // At most one approved and one pending credential per user (the approved one is bound to one device).
        DB::statement("CREATE UNIQUE INDEX webauthn_credentials_one_approved ON webauthn_credentials (user_id) WHERE status = 'approved'");
        DB::statement("CREATE UNIQUE INDEX webauthn_credentials_one_pending ON webauthn_credentials (user_id) WHERE status = 'pending'");

        Schema::create('webauthn_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->cascadeOnDelete();
            $table->string('purpose', 12);
            $table->uuid('checkin_intent_id')->nullable()->unique();
            $table->string('challenge', 100); // base64url random bytes
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'purpose']);
        });
        DB::statement("ALTER TABLE webauthn_challenges ADD CONSTRAINT webauthn_challenges_purpose_check CHECK (purpose IN ('registration','checkin'))");

        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('webauthn_credential_id')->nullable()->after('faceid_verified')->constrained('webauthn_credentials')->nullOnDelete();
        });

        // Merge `faceid_required` into `faceid_required_for_checkin` (same meaning; the old key was never enforced).
        if (! DB::table('settings')->where('key', 'faceid_required_for_checkin')->exists()) {
            DB::table('settings')->where('key', 'faceid_required')->update(['key' => 'faceid_required_for_checkin']);
        }
        DB::table('settings')->where('key', 'faceid_required')->delete();
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webauthn_credential_id');
        });
        Schema::dropIfExists('webauthn_challenges');
        Schema::dropIfExists('webauthn_credentials');
    }
};
