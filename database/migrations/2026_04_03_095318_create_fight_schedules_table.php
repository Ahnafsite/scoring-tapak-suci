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
        Schema::create('fight_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('partai_id')->nullable();
            $table->string('match_code')->nullable();
            $table->integer('match_number')->nullable();
            $table->string('athlete_yellow')->nullable();
            $table->string('athlete_blue')->nullable();
            $table->string('contingent_yellow')->nullable();
            $table->string('contingent_blue')->nullable();
            $table->enum('winner_corner', ['yellow', 'blue', 'draw', 'blue_draw', 'yellow_draw'])->nullable();
            $table->string('winner_status')->nullable();
            $table->string('match_round')->nullable();
            $table->string('category')->nullable();
            $table->string('group')->nullable();
            $table->enum('status', ['not_started', 'ongoing', 'paused', 'done'])->default('not_started');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fight_schedules');
    }
};
