<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->json('service_staff')->nullable()->after('service_categories');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('staff')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('staff');
        });

        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('service_staff');
        });
    }
};
