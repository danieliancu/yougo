<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('source_fingerprint', 64)->nullable()->after('working_hours');
            $table->unique(['salon_id', 'source_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // The composite unique index is currently the only index covering salon_id,
            // so it backs the salon_id foreign key — a plain index must replace it before
            // it can be dropped, or MySQL refuses ("needed in a foreign key constraint").
            $table->index('salon_id');
            $table->dropUnique(['salon_id', 'source_fingerprint']);
            $table->dropColumn('source_fingerprint');
        });
    }
};
