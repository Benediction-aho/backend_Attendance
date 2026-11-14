<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hashes of device tokens ended because the account was blocked, locked or deleted, so the next
        // request with such a token gets a 401 with code "account_blocked" instead of a bare 401.
        Schema::create('revoked_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('reason', 20);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_sessions');
    }
};
