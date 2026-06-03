<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Platform Admin');
            $table->string('username')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement(
            'insert into platform_admins (name, username, password, created_at, updated_at)
            values (\'Platform Admin\', \'admin\', \'$2y$10$cwmEHQP7yXzd2yTK74TfVuWoqSXgWgpger1Yw7kKPA/drQHQiZ2lm\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
