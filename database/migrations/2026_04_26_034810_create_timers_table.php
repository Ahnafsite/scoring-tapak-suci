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
        Schema::create('timers', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_display')->default(false);
            $table->timestamp('started_at', 3)->nullable();
            $table->unsignedBigInteger('started_at_milliseconds')->nullable();
            $table->string('status')->default('stopped');
            $table->boolean('is_countdown')->default(true);
            $table->unsignedInteger('second')->default(120);
            $table->boolean('is_autostop')->default(false);
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->unsignedInteger('elapsed_milliseconds')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timers');
    }
};
