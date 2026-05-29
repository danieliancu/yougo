<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('channel');
            $table->string('external_contact_id')->nullable()->after('provider');
            $table->string('external_sender')->nullable()->after('external_contact_id');
            $table->json('metadata')->nullable()->after('summary');

            $table->index(['salon_id', 'channel', 'external_contact_id']);
        });

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->string('direction')->nullable()->after('role');
            $table->string('provider')->nullable()->after('direction');
            $table->string('provider_message_id')->nullable()->after('provider');
            $table->json('metadata')->nullable()->after('content');

            $table->unique('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropUnique(['provider_message_id']);
            $table->dropColumn(['direction', 'provider', 'provider_message_id', 'metadata']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['salon_id', 'channel', 'external_contact_id']);
            $table->dropColumn(['provider', 'external_contact_id', 'external_sender', 'metadata']);
        });
    }
};
