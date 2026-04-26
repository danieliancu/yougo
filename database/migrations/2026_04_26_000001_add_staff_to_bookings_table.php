<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings') || Schema::hasColumn('bookings', 'staff')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->json('staff')->nullable()->after('client_phone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings') || ! Schema::hasColumn('bookings', 'staff')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('staff');
        });
    }
};
