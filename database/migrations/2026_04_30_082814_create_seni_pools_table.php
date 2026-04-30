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
        Schema::create('seni_pools', function (Blueprint $table) {
            $table->id();
            $table->integer('no_pool_babak_id')->unique();
            $table->string('round_match');
            $table->string('group');
            $table->string('category');
            $table->string('no_pool');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seni_pools');
    }
};
