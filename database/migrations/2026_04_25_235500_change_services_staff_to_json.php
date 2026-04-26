<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('staff_json')->nullable()->after('type');
        });

        DB::table('services')
            ->select(['id', 'staff'])
            ->orderBy('id')
            ->get()
            ->each(function ($service) {
                $staff = trim((string) ($service->staff ?? ''));
                DB::table('services')
                    ->where('id', $service->id)
                    ->update([
                        'staff_json' => $staff !== '' ? json_encode([$staff], JSON_UNESCAPED_UNICODE) : null,
                    ]);
            });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('staff');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('staff_json', 'staff');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('staff_text')->nullable()->after('type');
        });

        DB::table('services')
            ->select(['id', 'staff'])
            ->orderBy('id')
            ->get()
            ->each(function ($service) {
                $staff = json_decode($service->staff ?? 'null', true);
                $firstStaff = is_array($staff) ? (string) ($staff[0] ?? '') : '';

                DB::table('services')
                    ->where('id', $service->id)
                    ->update([
                        'staff_text' => $firstStaff !== '' ? $firstStaff : null,
                    ]);
            });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('staff');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->renameColumn('staff_text', 'staff');
        });
    }
};
