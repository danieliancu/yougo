<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('content')->nullable();
            $table->string('source_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['salon_id', 'source_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};
