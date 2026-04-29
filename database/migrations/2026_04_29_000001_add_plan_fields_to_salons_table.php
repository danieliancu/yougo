<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('plan')->default('free')->after('name');
            $table->timestamp('plan_started_at')->nullable()->after('plan');
            $table->timestamp('trial_ends_at')->nullable()->after('plan_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['plan', 'plan_started_at', 'trial_ends_at']);
        });
    }
};
