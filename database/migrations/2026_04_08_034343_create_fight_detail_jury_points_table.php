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
        Schema::create('fight_detail_jury_points', function (Blueprint $table) {
            $table->id();
            $table->integer('jury_number');
            $table->integer('round_number');
            $table->foreignId('ref_score_id')->nullable()->constrained('ref_scores')->cascadeOnDelete();
            $table->foreignId('ref_punishment_id')->nullable()->constrained('ref_punishments')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fight_detail_jury_points');
    }
};
