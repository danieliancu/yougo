<?php

use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IndustryController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OnboardingImportController;
use App\Http\Controllers\OnboardingImportPageController;
use App\Http\Controllers\PlatformAdminAuthController;
use App\Http\Controllers\PlatformAdminController;
use App\Http\Controllers\PublicYouGoAssistantController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceImageImportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TwilioWhatsAppStatusController;
use App\Http\Controllers\TwilioWhatsAppWebhookController;
use App\Http\Controllers\WhatsappSettingsController;
use App\Http\Controllers\WidgetController;
use App\Support\YouGoServices;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Landing', [
    'plans' => YouGoServices::plans(),
    'services' => YouGoServices::all(),
]))->name('home');
Route::get('/industries/{businessTypeSlug}', [IndustryController::class, 'show'])->name('industries.show');
Route::get('/industries/{businessTypeSlug}/{industrySlug}', [IndustryController::class, 'redirectLegacy'])->name('industries.legacy');
Route::post('/yougo-assistant/chat', [PublicYouGoAssistantController::class, 'chat'])
    ->middleware('throttle:20,1')
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('yougo-assistant.chat');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::redirect('/dashboard/chat-audio', '/dashboard/widget')->name('dashboard.chat-audio.redirect');
    Route::get('/dashboard/customers/{customer}', [CustomerController::class, 'show'])->name('dashboard.customers.show');
    Route::patch('/dashboard/customers/{customer}/notes', [CustomerController::class, 'updateNotes'])->name('dashboard.customers.notes.update');
    Route::get('/dashboard/{section}', DashboardController::class)
        ->whereIn('section', ['overview', 'onboarding', 'ai-settings', 'conversations', 'voice-calls', 'whatsapp', 'locations', 'staff', 'services', 'bookings', 'customers', 'requests', 'widget', 'billing', 'settings'])
        ->name('dashboard.section');

    Route::post('/onboarding/skip', [OnboardingController::class, 'skip'])->name('onboarding.skip');
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/capabilities/confirm', [OnboardingController::class, 'confirmCapabilities'])->name('onboarding.capabilities.confirm');

    Route::get('/onboarding/import', OnboardingImportPageController::class)->name('onboarding.import.page');
    Route::post('/onboarding/import', [OnboardingImportController::class, 'start'])->name('onboarding.import.start');
    Route::get('/onboarding/import/active', [OnboardingImportController::class, 'active'])->name('onboarding.import.active');
    Route::get('/onboarding/import/{onboardingDraft}', [OnboardingImportController::class, 'status'])->name('onboarding.import.status');
    Route::post('/onboarding/import/{onboardingDraft}/retry', [OnboardingImportController::class, 'retry'])->name('onboarding.import.retry');
    Route::patch('/onboarding/import/{onboardingDraft}', [OnboardingImportController::class, 'update'])->name('onboarding.import.update');
    Route::post('/onboarding/import/{onboardingDraft}/confirm', [OnboardingImportController::class, 'confirm'])->name('onboarding.import.confirm');

    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');

    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::post('/dashboard/services/import-image/analyze', [ServiceImageImportController::class, 'analyze'])
        ->middleware('throttle:10,1')
        ->name('services.import-image.analyze');
    Route::post('/dashboard/services/import-image/store', [ServiceImageImportController::class, 'store'])
        ->name('services.import-image.store');
    Route::put('/services/categories', [ServiceController::class, 'updateCategories'])->name('services.categories.update');
    Route::put('/services/staff', [ServiceController::class, 'updateStaff'])->name('services.staff.update');
    Route::post('/services/bulk-update', [ServiceController::class, 'bulkUpdate'])->name('services.bulk-update');
    Route::post('/services/bulk-delete', [ServiceController::class, 'bulkDelete'])->name('services.bulk-delete');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::put('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::post('/bookings', [BookingController::class, 'store'])->name('bookings.store');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');

    Route::patch('/customer-requests/{customerRequest}', [CustomerRequestController::class, 'update'])->name('customer-requests.update');

    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');
    Route::patch('/dashboard/conversations/{conversation}/booking-change-requests/{requestId}/resolve', [ConversationController::class, 'resolveBookingChangeRequest'])
        ->name('conversations.booking-change-requests.resolve');

    Route::put('/ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');
    Route::post('/ai-settings/mark-complete', [AiSettingsController::class, 'markSetupComplete'])->name('ai-settings.mark-complete');
    Route::post('/settings/language', [SettingsController::class, 'updateLanguage'])->name('settings.language.update');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/widget-settings', [WidgetController::class, 'updateSettings'])->name('widget-settings.update');
    Route::post('/widget-settings/mark-complete', [WidgetController::class, 'markSetupComplete'])->name('widget-settings.mark-complete');
    Route::post('/dashboard/whatsapp/request-activation', [WhatsappSettingsController::class, 'requestActivation'])
        ->name('dashboard.whatsapp.request-activation');
    Route::post('/dashboard/whatsapp/setup-request', [WhatsappSettingsController::class, 'setupRequest'])
        ->name('dashboard.whatsapp.setup-request');
    Route::patch('/dashboard/whatsapp/toggle', [WhatsappSettingsController::class, 'toggle'])
        ->name('dashboard.whatsapp.toggle');
    Route::post('/dashboard/whatsapp/test-message', [WhatsappSettingsController::class, 'testMessage'])
        ->name('dashboard.whatsapp.test-message');
    Route::post('/dashboard/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('/dashboard/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');
    Route::delete('/account', [SettingsController::class, 'destroy'])->name('account.destroy');
});

Route::prefix('platform-admin')
    ->name('platform-admin.')
    ->group(function (): void {
        Route::get('/login', [PlatformAdminAuthController::class, 'create'])->name('login');
        Route::post('/login', [PlatformAdminAuthController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('login.store');
    });

Route::middleware('platform_admin')
    ->prefix('platform-admin')
    ->name('platform-admin.')
    ->group(function (): void {
        Route::post('/logout', [PlatformAdminAuthController::class, 'destroy'])->name('logout');
        Route::get('/settings', [PlatformAdminAuthController::class, 'edit'])->name('settings');
        Route::put('/settings', [PlatformAdminAuthController::class, 'update'])->name('settings.update');
        Route::get('/', [PlatformAdminController::class, 'overview'])->name('overview');
        Route::get('/businesses', [PlatformAdminController::class, 'businesses'])->name('businesses');
        Route::get('/businesses/{salon}', [PlatformAdminController::class, 'business'])->name('businesses.show');
        Route::get('/whatsapp-onboarding', [PlatformAdminController::class, 'whatsappOnboarding'])->name('whatsapp-onboarding');
        Route::get('/usage', [PlatformAdminController::class, 'usage'])->name('usage');
        Route::get('/issues', [PlatformAdminController::class, 'issues'])->name('issues');
    });

Route::post('/stripe/webhook', StripeWebhookController::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('stripe.webhook');

Route::post('/twilio/whatsapp/webhook', TwilioWhatsAppWebhookController::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('twilio.whatsapp.webhook');

Route::post('/twilio/whatsapp/status', TwilioWhatsAppStatusController::class)
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('twilio.whatsapp.status');

Route::get('/widget/{widgetKey}.js', [WidgetController::class, 'script'])->name('widget.script');
Route::get('/widget/{widgetKey}', [WidgetController::class, 'show'])->name('widget.show');
Route::post('/widget/{widgetKey}/chat', [WidgetController::class, 'chat'])
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('widget.chat');

Route::get('/assistant/{salon}', [AssistantController::class, 'show'])->name('assistant.show');
Route::post('/assistant/{salon}/chat', [AssistantController::class, 'chat'])
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('assistant.chat');
Route::post('/assistant/{salon}/abandon', [AssistantController::class, 'abandon'])
    ->withoutMiddleware(VerifyCsrfToken::class)
    ->name('assistant.abandon');
