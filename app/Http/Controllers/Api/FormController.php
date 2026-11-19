<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoBooking;
use App\Models\Lead;
use App\Services\EmailService;
use App\Services\LeadCaptureService;
use App\Support\LeadAttribution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public website forms. Ported from old/src/app/api/contact and old/src/app/api/demo (POST). */
class FormController extends Controller
{
    /** POST /api/contact */
    public function contact(Request $request, EmailService $email): JsonResponse
    {
        $name = $request->input('name');
        $mail = $request->input('email');
        $message = $request->input('message');

        if (! $name || ! $mail || ! $message) {
            return response()->json(['error' => 'Name, email, and message are required'], 400);
        }

        $lead = Lead::create([
            'name' => $name,
            'email' => $mail,
            'phone' => $request->input('phone') ?: null,
            'message' => $message,
            'product_interest' => 'general',
            'source' => 'website',
            'status' => 'new',
            ...LeadAttribution::fromRequest($request),
        ]);

        $email->notifySales("Contact Form: {$name}", 'New Contact Message', [
            'Name' => $name,
            'Email' => $mail,
            'Phone' => $request->input('phone'),
            'Message' => $message,
        ]);

        return response()->json(['success' => true, 'lead' => ['id' => $lead->id]], 201);
    }

    /**
     * POST /api/demo — short demo form (name, Indian mobile, product, message,
     * consent). Older payloads with email / organization / scheduled_date still work.
     */
    public function demo(Request $request, LeadCaptureService $capture): JsonResponse
    {
        if (LeadCaptureService::isSpam($request)) {
            return response()->json(['success' => true], 201); // pretend, store nothing
        }

        $scheduledDate = $request->input('scheduled_date');
        $lead = $capture->capture($request, 'form', $scheduledDate ? ['status' => 'demo_scheduled'] : []);

        if ($scheduledDate) {
            DemoBooking::create([
                'lead_id' => $lead->id,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $request->input('scheduled_time') ?: '10:00',
                'notes' => $request->input('message') ?: null,
                'status' => 'scheduled',
            ]);
        }

        return response()->json(['success' => true, 'lead' => ['id' => $lead->id]], 201);
    }
}
