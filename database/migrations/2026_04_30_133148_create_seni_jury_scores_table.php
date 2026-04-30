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
        Schema::create('seni_jury_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seni_single_match_id')->constrained('seni_single_matches')->cascadeOnDelete();
            $table->unsignedTinyInteger('jury_number');
            $table->decimal('wiraga', 8, 3)->nullable();
            $table->decimal('wirasa', 8, 3)->nullable();
            $table->decimal('wirama', 8, 3)->nullable();
            $table->decimal('kualitas_teknik', 8, 3)->nullable();
            $table->decimal('kuantitas_teknik', 8, 3)->nullable();
            $table->decimal('ketangkasan', 8, 3)->nullable();
            $table->decimal('stamina', 8, 3)->nullable();
            $table->decimal('kemantapan', 8, 3)->nullable();
            $table->decimal('musik', 8, 3)->nullable();
            $table->decimal('total_score', 8, 3)->default(0);
            $table->boolean('is_accepted')->default(false);
            $table->timestamps();

            $table->unique(['seni_single_match_id', 'jury_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seni_jury_scores');
    }
};
