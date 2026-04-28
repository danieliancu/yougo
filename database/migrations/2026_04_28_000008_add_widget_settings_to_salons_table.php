<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->string('widget_key')->nullable()->unique()->after('business_type');
            $table->boolean('widget_enabled')->default(true)->after('widget_key');
            $table->json('widget_allowed_domains')->nullable()->after('widget_enabled');
            $table->string('widget_primary_color', 20)->nullable()->after('widget_allowed_domains');
            $table->string('widget_position')->nullable()->after('widget_primary_color');
        });

        DB::table('salons')
            ->whereNull('widget_key')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($salon) {
                do {
                    $key = Str::random(40);
                } while (DB::table('salons')->where('widget_key', $key)->exists());

                DB::table('salons')->where('id', $salon->id)->update([
                    'widget_key' => $key,
                    'widget_enabled' => true,
                    'widget_position' => 'bottom-right',
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropUnique(['widget_key']);
            $table->dropColumn([
                'widget_key',
                'widget_enabled',
                'widget_allowed_domains',
                'widget_primary_color',
                'widget_position',
            ]);
        });
    }
};
