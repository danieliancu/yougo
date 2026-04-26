<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
            $table->string('timezone')->default('Europe/London')->after('logo_path');
            $table->string('industry')->nullable()->after('timezone');
            $table->string('country', 2)->nullable()->after('industry');
            $table->string('website')->nullable()->after('country');
            $table->string('business_phone')->nullable()->after('website');
            $table->string('notification_email')->nullable()->after('business_phone');
            $table->boolean('email_notifications')->default(true)->after('notification_email');
            $table->boolean('missed_call_alerts')->default(true)->after('email_notifications');
            $table->boolean('booking_confirmations')->default(true)->after('missed_call_alerts');
            $table->string('display_language')->default('ro')->after('booking_confirmations');
            $table->string('date_format')->default('DD/MM/YYYY')->after('display_language');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path',
                'timezone',
                'industry',
                'country',
                'website',
                'business_phone',
                'notification_email',
                'email_notifications',
                'missed_call_alerts',
                'booking_confirmations',
                'display_language',
                'date_format',
            ]);
        });
    }
};
