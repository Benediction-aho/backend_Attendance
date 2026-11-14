<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254);
            $table->string('role', 20)->default('employee');
            $table->string('employee_type', 20)->default('employee');
            $table->string('job_title', 150)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('phone', 30)->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address', 255)->nullable();
            $table->string('photo_path', 255)->nullable();
            // Hash of the identification code or of the user's own password. Null for super admins (one-time codes).
            $table->string('password')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestampTz('initial_code_expires_at')->nullable();
            $table->timestampTz('password_changed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['status', 'role']);
        });

        // Email is unique among non-deleted accounts; stored lowercase.
        DB::statement('CREATE UNIQUE INDEX users_email_active_unique ON users (lower(email)) WHERE deleted_at IS NULL');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin','admin','employee'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (employee_type IN ('intern','employee'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('pending','active','blocked','locked','deleted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
