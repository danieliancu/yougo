<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request (Task 4): a solicitation created from a conversation, kept as a separate
 * table from `bookings` — it must not need an appointment's required fields (date/time/
 * location aren't guaranteed) and must not force qualified requests into a slot they
 * don't fit. No `conversation_id` column here on purpose: the conversation->result link
 * is owned solely by `conversations.result_type`/`result_id` (see
 * add_result_columns_to_conversations_table) to avoid two independently-writable sources
 * of truth for the same relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('channel');
            $table->string('type')->default('general');
            $table->string('status')->default('new');
            $table->string('priority')->default('normal');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('structured_data')->nullable();
            $table->string('preferred_date')->nullable();
            $table->string('preferred_window')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['salon_id', 'idempotency_key']);
            $table->index(['salon_id', 'status']);
            $table->index(['salon_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_requests');
    }
};
