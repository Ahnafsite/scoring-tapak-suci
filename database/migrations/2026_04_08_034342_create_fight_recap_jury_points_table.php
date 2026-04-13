<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fight_recap_jury_points', function (Blueprint $table) {
            $table->id();
            $table->integer('round_number');
            $table->integer('jury_one_total_poin_blue')->default(0);
            $table->integer('jury_two_total_poin_blue')->default(0);
            $table->integer('jury_three_total_poin_blue')->default(0);
            $table->integer('jury_four_total_poin_blue')->default(0);
            $table->integer('jury_one_total_poin_yellow')->default(0);
            $table->integer('jury_two_total_poin_yellow')->default(0);
            $table->integer('jury_three_total_poin_yellow')->default(0);
            $table->integer('jury_four_total_poin_yellow')->default(0);
            $table->string('jury_one_winner')->nullable();
            $table->string('jury_two_winner')->nullable();
            $table->string('jury_three_winner')->nullable();
            $table->string('jury_four_winner')->nullable();
            $table->integer('total_poin_yellow')->default(0);
            $table->integer('total_poin_blue')->default(0);
            $table->string('winner')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fight_recap_jury_points');
    }
};
