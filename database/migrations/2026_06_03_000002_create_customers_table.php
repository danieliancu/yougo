<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_normalized')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['salon_id', 'phone_normalized']);
            $table->unique(['salon_id', 'email_normalized']);
            $table->index(['salon_id', 'last_seen_at']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('salon_id')->constrained()->nullOnDelete();
            $table->index(['salon_id', 'customer_id']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
            $table->index(['salon_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['salon_id', 'customer_id']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['salon_id', 'customer_id']);
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
