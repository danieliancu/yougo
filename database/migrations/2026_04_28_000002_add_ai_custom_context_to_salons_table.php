<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (! Schema::hasColumn('salons', 'ai_custom_context')) {
                $table->json('ai_custom_context')->nullable()->after('ai_main_focus');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('ai_custom_context');
        });
    }
};
