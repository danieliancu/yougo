<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite' && Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'appointment_id')) {
            try {
                Schema::table('conversations', function (Blueprint $table) {
                    $table->dropForeign(['appointment_id']);
                });
            } catch (Throwable) {
                //
            }
        }

        if (Schema::hasTable('appointments') && ! Schema::hasTable('bookings')) {
            Schema::rename('appointments', 'bookings');
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'appointment_id') && ! Schema::hasColumn('conversations', 'booking_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->renameColumn('appointment_id', 'booking_id');
            });
        }

        if ($driver !== 'sqlite' && Schema::hasTable('conversations') && Schema::hasTable('bookings') && Schema::hasColumn('conversations', 'booking_id')) {
            try {
                Schema::table('conversations', function (Blueprint $table) {
                    $table->foreign('booking_id')->references('id')->on('bookings')->nullOnDelete();
                });
            } catch (Throwable) {
                //
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'sqlite' && Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'booking_id')) {
            try {
                Schema::table('conversations', function (Blueprint $table) {
                    $table->dropForeign(['booking_id']);
                });
            } catch (Throwable) {
                //
            }
        }

        if (Schema::hasTable('bookings') && ! Schema::hasTable('appointments')) {
            Schema::rename('bookings', 'appointments');
        }

        if (Schema::hasTable('conversations') && Schema::hasColumn('conversations', 'booking_id') && ! Schema::hasColumn('conversations', 'appointment_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->renameColumn('booking_id', 'appointment_id');
            });
        }

        if ($driver !== 'sqlite' && Schema::hasTable('conversations') && Schema::hasTable('appointments') && Schema::hasColumn('conversations', 'appointment_id')) {
            try {
                Schema::table('conversations', function (Blueprint $table) {
                    $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
                });
            } catch (Throwable) {
                //
            }
        }
    }
};
