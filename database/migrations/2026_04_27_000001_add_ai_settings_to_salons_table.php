<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('ai_assistant_name')->default('Bella')->after('service_staff');
            $table->string('ai_tone')->default('polite')->after('ai_assistant_name');
            $table->string('ai_response_style')->default('short')->after('ai_tone');
            $table->string('ai_language_mode')->default('auto')->after('ai_response_style');
            $table->text('ai_custom_instructions')->nullable()->after('ai_language_mode');
            $table->text('ai_business_summary')->nullable()->after('ai_custom_instructions');
            $table->boolean('ai_booking_enabled')->default(true)->after('ai_business_summary');
            $table->boolean('ai_collect_phone')->default(true)->after('ai_booking_enabled');
            $table->text('ai_handoff_message')->nullable()->after('ai_collect_phone');
            $table->string('ai_unknown_answer_policy')->default('say_unknown')->after('ai_handoff_message');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'ai_assistant_name',
                'ai_tone',
                'ai_response_style',
                'ai_language_mode',
                'ai_custom_instructions',
                'ai_business_summary',
                'ai_booking_enabled',
                'ai_collect_phone',
                'ai_handoff_message',
                'ai_unknown_answer_policy',
            ]);
        });
    }
};
