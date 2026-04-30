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
        Schema::create('seni_single_matches', function (Blueprint $table) {
            $table->id();
            $table->integer('no_pool_babak_id')->index();
            $table->integer('bkp_id')->index();
            $table->string('matches_code');
            $table->string('atletes');
            $table->string('contingent');
            $table->string('type', 12);
            $table->string('category');
            $table->string('group');
            $table->string('status', 12)->default('not_started');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_disqualified')->default(false);
            $table->boolean('is_passed')->default(false);
            $table->string('round_match');
            $table->integer('no_order');
            $table->decimal('total_score', 8, 3)->nullable();
            $table->decimal('total_wiraga', 8, 3)->nullable();
            $table->decimal('total_wirasa', 8, 3)->nullable();
            $table->decimal('total_wirama', 8, 3)->nullable();
            $table->decimal('total_kualitas_teknik', 8, 3)->nullable();
            $table->decimal('total_kuantitas_teknik', 8, 3)->nullable();
            $table->decimal('total_ketangkasan', 8, 3)->nullable();
            $table->decimal('total_stamina', 8, 3)->nullable();
            $table->decimal('total_kemantapan', 8, 3)->nullable();
            $table->decimal('total_musik', 8, 3)->nullable();
            $table->decimal('total_punishment', 8, 3)->nullable();
            $table->integer('time')->nullable();
            $table->decimal('deviasi', 8, 3)->nullable();
            $table->integer('rank')->nullable();
            $table->timestamps();

            $table->unique(['no_pool_babak_id', 'bkp_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seni_single_matches');
    }
};
