<?php

use App\Models\Salon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (! Schema::hasColumn('salons', 'mode')) {
                $table->string('mode')->default(Salon::MODE_APPOINTMENT)->after('industry');
            }

            if (! Schema::hasColumn('salons', 'business_type')) {
                $table->string('business_type')->nullable()->after('mode');
            }

            if (! Schema::hasColumn('salons', 'onboarding_completed')) {
                $table->boolean('onboarding_completed')->default(false)->after('business_type');
            }

            if (! Schema::hasColumn('salons', 'industry')) {
                $table->string('industry')->nullable()->after('timezone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn([
                'mode',
                'business_type',
                'onboarding_completed',
            ]);
        });
    }
};
