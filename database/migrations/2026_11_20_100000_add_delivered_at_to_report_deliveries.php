<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // P5-05: sent_at is the claim (queued); delivered_at is set once the provider accepted the email.
    public function up(): void
    {
        Schema::table('report_deliveries', function (Blueprint $table) {
            $table->timestampTz('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_deliveries', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
