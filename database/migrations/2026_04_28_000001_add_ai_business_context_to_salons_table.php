<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (! Schema::hasColumn('salons', 'ai_industry_categories')) {
                $table->json('ai_industry_categories')->nullable()->after('ai_business_summary');
            }

            if (! Schema::hasColumn('salons', 'ai_main_focus')) {
                $table->string('ai_main_focus')->nullable()->after('ai_industry_categories');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'ai_industry_categories',
                'ai_main_focus',
            ]);
        });
    }
};
