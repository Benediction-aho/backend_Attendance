<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Security report P1-18: atomic hit-first counters (one upsert per hit, never a lost first hit). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limit_counters', function (Blueprint $table) {
            $table->string('key', 255)->primary();
            $table->unsignedInteger('hits');
            $table->timestampTz('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_counters');
    }
};
