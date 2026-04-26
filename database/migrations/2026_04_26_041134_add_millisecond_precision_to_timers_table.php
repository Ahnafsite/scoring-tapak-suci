<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('timers', 'started_at_milliseconds')) {
            Schema::table('timers', function (Blueprint $table) {
                $table->unsignedBigInteger('started_at_milliseconds')->nullable()->after('started_at');
            });
        }

        if (! Schema::hasColumn('timers', 'elapsed_milliseconds')) {
            Schema::table('timers', function (Blueprint $table) {
                $table->unsignedInteger('elapsed_milliseconds')->default(0)->after('elapsed_seconds');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('timers', 'started_at_milliseconds')) {
            Schema::table('timers', function (Blueprint $table) {
                $table->dropColumn('started_at_milliseconds');
            });
        }

        if (Schema::hasColumn('timers', 'elapsed_milliseconds')) {
            Schema::table('timers', function (Blueprint $table) {
                $table->dropColumn('elapsed_milliseconds');
            });
        }
    }
};
