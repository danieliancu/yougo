<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'currency')) {
            return;
        }

        DB::table('services')
            ->whereNotNull('currency')
            ->update(['currency' => null]);
    }

    public function down(): void
    {
        // The original per-service override intent cannot be reconstructed safely.
    }
};
