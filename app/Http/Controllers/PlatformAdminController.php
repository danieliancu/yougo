<?php

namespace App\Http\Controllers;

use App\Models\Salon;
use App\Services\PlatformAdmin\PlatformAdminService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function __construct(private readonly PlatformAdminService $admin) {}

    public function overview(): Response
    {
        return Inertia::render('PlatformAdmin/Overview', [
            'payload' => $this->admin->overview(),
        ]);
    }

    public function businesses(Request $request): Response
    {
        return Inertia::render('PlatformAdmin/Businesses', [
            'payload' => $this->admin->businesses($request->only(['search', 'plan', 'subscription_status', 'whatsapp_status'])),
        ]);
    }

    public function business(Salon $salon): Response
    {
        return Inertia::render('PlatformAdmin/BusinessDetail', [
            'payload' => $this->admin->businessDetail($salon),
        ]);
    }

    public function whatsappOnboarding(Request $request): Response
    {
        return Inertia::render('PlatformAdmin/WhatsappOnboarding', [
            'payload' => $this->admin->whatsappOnboarding($request->string('status')->toString() ?: 'requested'),
        ]);
    }

    public function usage(): Response
    {
        return Inertia::render('PlatformAdmin/Usage', [
            'payload' => $this->admin->usage(),
        ]);
    }

    public function issues(): Response
    {
        return Inertia::render('PlatformAdmin/Issues', [
            'payload' => $this->admin->issues(),
        ]);
    }
}
