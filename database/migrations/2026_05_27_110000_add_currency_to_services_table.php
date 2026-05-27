<?php

use App\Support\BusinessLocalization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'currency')) {
                $table->string('currency', 10)->nullable()->after('price');
            }
        });

        DB::table('services')
            ->select(['id', 'salon_id', 'currency'])
            ->orderBy('id')
            ->chunkById(100, function ($services) {
                foreach ($services as $service) {
                    if (filled($service->currency)) {
                        continue;
                    }

                    $salon = DB::table('salons')
                        ->select(['country', 'currency'])
                        ->where('id', $service->salon_id)
                        ->first();

                    DB::table('services')->where('id', $service->id)->update([
                        'currency' => BusinessLocalization::normalizeServiceCurrency($salon?->currency, $salon?->country),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (Schema::hasColumn('services', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }
};
