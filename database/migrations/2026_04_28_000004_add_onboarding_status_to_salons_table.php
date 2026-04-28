<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->boolean('onboarding_skipped')->default(false)->after('onboarding_completed');
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_skipped');
            $table->timestamp('onboarding_skipped_at')->nullable()->after('onboarding_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_skipped',
                'onboarding_completed_at',
                'onboarding_skipped_at',
            ]);
        });
    }
};
