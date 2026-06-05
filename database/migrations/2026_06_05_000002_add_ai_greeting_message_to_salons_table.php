<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->text('ai_greeting_message')->nullable()->after('ai_language_mode');
            $table->text('ai_about_business')->nullable()->after('ai_business_summary');
            $table->text('ai_policies')->nullable()->after('ai_about_business');
            $table->text('ai_faq')->nullable()->after('ai_policies');
            $table->text('ai_recommendations')->nullable()->after('ai_faq');
            $table->text('ai_avoid')->nullable()->after('ai_recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_greeting_message',
                'ai_about_business',
                'ai_policies',
                'ai_faq',
                'ai_recommendations',
                'ai_avoid',
            ]);
        });
    }
};
