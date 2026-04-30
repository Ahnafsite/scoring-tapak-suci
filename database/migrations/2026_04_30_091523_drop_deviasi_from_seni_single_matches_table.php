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
        Schema::table('seni_single_matches', function (Blueprint $table) {
            $table->dropColumn('deviasi');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seni_single_matches', function (Blueprint $table) {
            $table->decimal('deviasi', 8, 3)->nullable()->after('time');
        });
    }
};
