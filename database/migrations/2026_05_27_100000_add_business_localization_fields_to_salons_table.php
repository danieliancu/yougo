<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (! Schema::hasColumn('salons', 'timezone')) {
                $table->string('timezone', 80)->nullable()->after('logo_path');
            }

            if (! Schema::hasColumn('salons', 'currency')) {
                $table->string('currency', 10)->nullable()->after('country');
            }

            if (! Schema::hasColumn('salons', 'phone_prefix')) {
                $table->string('phone_prefix', 10)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('salons', 'date_format')) {
                $table->string('date_format', 30)->nullable()->after('display_language');
            }
        });

        DB::table('salons')
            ->select(['id', 'country', 'timezone', 'currency', 'phone_prefix', 'date_format'])
            ->orderBy('id')
            ->chunkById(100, function ($salons) {
                foreach ($salons as $salon) {
                    $country = strtoupper(trim((string) $salon->country));
                    $country = $country === 'UK' ? 'GB' : $country;
                    $country = in_array($country, ['RO', 'GB'], true) ? $country : 'RO';
                    $defaults = $this->defaultsFor($country);

                    DB::table('salons')->where('id', $salon->id)->update([
                        'country' => $country,
                        'timezone' => filled($salon->timezone) ? $salon->timezone : $defaults['timezone'],
                        'currency' => filled($salon->currency) ? $salon->currency : $defaults['currency'],
                        'phone_prefix' => filled($salon->phone_prefix) ? $salon->phone_prefix : $defaults['phone_prefix'],
                        'date_format' => filled($salon->date_format) ? strtolower($salon->date_format) : $defaults['date_format'],
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (Schema::hasColumn('salons', 'currency')) {
                $table->dropColumn('currency');
            }

            if (Schema::hasColumn('salons', 'phone_prefix')) {
                $table->dropColumn('phone_prefix');
            }
        });
    }

    /** @return array<string, string> */
    private function defaultsFor(string $country): array
    {
        return $country === 'GB'
            ? ['currency' => 'GBP', 'phone_prefix' => '+44', 'timezone' => 'Europe/London', 'date_format' => 'dd/mm/yyyy']
            : ['currency' => 'RON', 'phone_prefix' => '+40', 'timezone' => 'Europe/Bucharest', 'date_format' => 'dd.mm.yyyy'];
    }
};
