<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->boolean('service_at_customer_location')->default(false)->after('business_phone');
            $table->json('opening_hours')->nullable()->after('service_at_customer_location');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn(['service_at_customer_location', 'opening_hours']);
        });
    }
};
