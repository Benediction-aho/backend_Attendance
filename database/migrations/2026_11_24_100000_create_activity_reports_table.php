<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity reports (owner request 2026-11-14): a PDF with a title, sent at any time by someone who tracks
 * attendance. Separate from the weekly Friday report. New table only (nothing existing is changed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date'); // server work date of the upload (Africa/Accra)
            $table->string('title', 150);
            $table->text('note')->nullable();
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestampTz('submitted_at');
            $table->timestampsTz();
            $table->softDeletesTz(); // removed by the author within the allowed delay (the file itself is deleted)
            $table->index(['user_id', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_reports');
    }
};
