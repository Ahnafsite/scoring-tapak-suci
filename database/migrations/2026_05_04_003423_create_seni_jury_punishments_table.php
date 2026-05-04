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
        Schema::create('seni_jury_punishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seni_single_match_id')->constrained('seni_single_matches')->cascadeOnDelete();
            $table->unsignedTinyInteger('jury_number');
            $table->decimal('waktu', 8, 3)->nullable();
            $table->decimal('keluar_garis', 8, 3)->nullable();
            $table->decimal('senjata_jatuh_atau_tidak_sesuai_deskripsi', 8, 3)->nullable();
            $table->decimal('senjata_tidak_jatuh_atau_tidak_sesuai_deskripsi', 8, 3)->nullable();
            $table->decimal('akeseoris_jatuh', 8, 3)->nullable();
            $table->timestamps();

            $table->unique(['seni_single_match_id', 'jury_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seni_jury_punishments');
    }
};
