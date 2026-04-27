<?php

use App\Http\Controllers\BookingController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Landing'))->name('home');

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
    Route::get('/dashboard/{section}', DashboardController::class)
        ->whereIn('section', ['overview', 'ai-settings', 'conversations', 'chat-audio', 'voice-calls', 'whatsapp', 'locations', 'services', 'bookings', 'settings'])
        ->name('dashboard.section');

    Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
    Route::put('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::put('/services/categories', [ServiceController::class, 'updateCategories'])->name('services.categories.update');
    Route::put('/services/staff', [ServiceController::class, 'updateStaff'])->name('services.staff.update');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    Route::put('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
    Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->name('bookings.destroy');

    Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy'])->name('conversations.destroy');

    Route::put('/ai-settings', [AiSettingsController::class, 'update'])->name('ai-settings.update');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

Route::get('/assistant/{salon}', [AssistantController::class, 'show'])->name('assistant.show');
Route::post('/assistant/{salon}/chat', [AssistantController::class, 'chat'])->name('assistant.chat');
Route::post('/assistant/{salon}/abandon', [AssistantController::class, 'abandon'])->name('assistant.abandon');
