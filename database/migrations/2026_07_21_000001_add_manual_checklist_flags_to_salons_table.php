<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->boolean('ai_assistant_setup_completed')->default(false)->after('ai_unknown_answer_policy');
            $table->boolean('widget_setup_completed')->default(false)->after('widget_position');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table): void {
            $table->dropColumn(['ai_assistant_setup_completed', 'widget_setup_completed']);
        });
    }
};
