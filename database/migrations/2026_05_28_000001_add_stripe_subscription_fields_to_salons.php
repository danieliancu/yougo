<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            if (! Schema::hasColumn('salons', 'stripe_customer_id')) {
                $table->string('stripe_customer_id')->nullable()->after('trial_ends_at')->index();
            }

            if (! Schema::hasColumn('salons', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id')->index();
            }

            if (! Schema::hasColumn('salons', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
            }

            if (! Schema::hasColumn('salons', 'subscription_status')) {
                $table->string('subscription_status')->nullable()->after('stripe_price_id');
            }

            if (! Schema::hasColumn('salons', 'subscription_current_period_end')) {
                $table->timestamp('subscription_current_period_end')->nullable()->after('subscription_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('salons', 'stripe_customer_id') ? 'stripe_customer_id' : null,
                Schema::hasColumn('salons', 'stripe_subscription_id') ? 'stripe_subscription_id' : null,
                Schema::hasColumn('salons', 'stripe_price_id') ? 'stripe_price_id' : null,
                Schema::hasColumn('salons', 'subscription_status') ? 'subscription_status' : null,
                Schema::hasColumn('salons', 'subscription_current_period_end') ? 'subscription_current_period_end' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
