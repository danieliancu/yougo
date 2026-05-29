<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('twilio');
            $table->string('requested_number')->nullable();
            $table->string('twilio_sender')->nullable();
            $table->string('display_number')->nullable();
            $table->string('status')->default('not_connected');
            $table->boolean('ai_enabled')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('salon_id');
            $table->unique('twilio_sender');
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_integrations');
    }
};
