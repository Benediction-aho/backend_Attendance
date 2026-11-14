<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Third employee type "other", with the text typed by the person (required with "other", empty otherwise). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_type_other', 60)->nullable()->after('employee_type');
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_type_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (employee_type IN ('intern','employee','other'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_other_check CHECK ((employee_type = 'other') = (employee_type_other IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_type_other_check');
        DB::statement("UPDATE users SET employee_type = 'employee' WHERE employee_type = 'other'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_type_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_type_check CHECK (employee_type IN ('intern','employee'))");
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('employee_type_other');
        });
    }
};
