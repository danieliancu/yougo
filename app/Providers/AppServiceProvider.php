<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Conversation;
use App\Models\CustomerRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Whitelists what `conversations.result_type` may point to (Conversation::result()).
        // enforceMorphMap (not plain morphMap) also rejects writes of an unmapped type, not
        // just reads — see ConversationResultService.
        Relation::enforceMorphMap([
            Conversation::RESULT_TYPE_BOOKING => Booking::class,
            Conversation::RESULT_TYPE_CUSTOMER_REQUEST => CustomerRequest::class,
        ]);
    }
}
