<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'voice_input_used')) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('voice_input_used');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('conversations', 'voice_input_used')) {
            return;
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('voice_input_used')->default(false)->after('channel');
        });
    }
};
