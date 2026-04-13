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
        Schema::create('fight_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fight_schedule_id')->nullable()->constrained('fight_schedules')->cascadeOnDelete();
            $table->string('partai_id')->nullable();
            $table->string('match_code')->nullable();
            $table->integer('match_number')->nullable();
            $table->string('atlete_yellow')->nullable();
            $table->string('atlete_blue')->nullable();
            $table->string('contingent_yellow')->nullable();
            $table->string('contingent_blue')->nullable();
            $table->string('winner_corner')->nullable();
            $table->string('match_round')->nullable();
            $table->string('category')->nullable();
            $table->string('group')->nullable();
            $table->string('status')->default('not_started');
            $table->integer('weight_yellow')->nullable();
            $table->integer('weight_blue')->nullable();
            $table->string('weight_status_yellow')->nullable();
            $table->string('weight_status_blue')->nullable();
            $table->integer('round_number')->default(1);
            $table->string('winner_status')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fight_matches');
    }
};
