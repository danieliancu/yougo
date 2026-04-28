<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedInteger('max_concurrent_bookings')->nullable()->after('hours');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedInteger('max_concurrent_bookings')->nullable()->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('max_concurrent_bookings');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('max_concurrent_bookings');
        });
    }
};
