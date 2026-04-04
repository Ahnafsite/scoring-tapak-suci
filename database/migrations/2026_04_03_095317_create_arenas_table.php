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
        Schema::create('arenas', function (Blueprint $table) {
            $table->id();
            $table->string('championship_name')->nullable();
            $table->integer('gelanggang_id')->nullable();
            $table->integer('sesi_tanding_id')->nullable();
            $table->integer('sesi_seni_id')->nullable();
            $table->string('arena_name')->nullable();
            $table->foreignId('jury_one_id')->nullable()->constrained('users');
            $table->foreignId('jury_two_id')->nullable()->constrained('users');
            $table->foreignId('jury_three_id')->nullable()->constrained('users');
            $table->foreignId('jury_four_id')->nullable()->constrained('users');
            $table->foreignId('jury_five_id')->nullable()->constrained('users');
            $table->foreignId('operator_id')->nullable()->constrained('users');
            $table->foreignId('sekretaris_id')->nullable()->constrained('users');
            $table->foreignId('streamer_id')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arenas');
    }
};
