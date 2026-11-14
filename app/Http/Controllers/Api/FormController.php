<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoBooking;
use App\Models\Lead;
use App\Services\EmailService;
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

    /** POST /api/demo — lead from demo form, plus an optional booking. */
    public function demo(Request $request, EmailService $email): JsonResponse
    {
        $name = $request->input('name');
        $mail = $request->input('email');

        if (! $name || ! $mail) {
            return response()->json(['error' => 'Name and email are required'], 400);
        }

        $message = $request->input('message');
        $scheduledDate = $request->input('scheduled_date');
        $scheduledTime = $request->input('scheduled_time') ?: '10:00';

        // Explicit product_interest wins; otherwise infer it from the message.
        $productInterest = $request->input('product_interest') ?: 'general';
        if ($productInterest === 'general') {
            $msg = strtolower((string) $message);
            if (str_contains($msg, 'school')) {
                $productInterest = 'school';
            } elseif (str_contains($msg, 'inventory') || str_contains($msg, 'pos') || str_contains($msg, 'retail')) {
                $productInterest = 'inventory';
            } elseif (str_contains($msg, 'dairy')) {
                $productInterest = 'dairy';
            }
        }

        $lead = Lead::create([
            'name' => $name,
            'organization' => $request->input('organization') ?: null,
            'city' => $request->input('city') ?: null,
            'phone' => $request->input('phone') ?: null,
            'email' => $mail,
            'message' => $message ?: null,
            'product_interest' => $productInterest,
            'source' => 'website',
            'status' => $scheduledDate ? 'demo_scheduled' : 'new',
            ...LeadAttribution::fromRequest($request),
        ]);

        if ($scheduledDate) {
            DemoBooking::create([
                'lead_id' => $lead->id,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'notes' => $message ?: null,
                'status' => 'scheduled',
            ]);
        }

        $fields = [
            'Name' => $name,
            'Organization' => $request->input('organization'),
            'Email' => $mail,
            'Phone' => $request->input('phone'),
            'City' => $request->input('city'),
            'Product Interest' => $productInterest,
            'Message' => $message,
        ];
        if ($scheduledDate) {
            $fields['Scheduled'] = "{$scheduledDate} at {$scheduledTime}";
        }

        $email->notifySales(
            "Demo Request: {$name} — ".($request->input('organization') ?: 'N/A'),
            'New Demo Request',
            $fields,
        );

        return response()->json(['success' => true, 'lead' => ['id' => $lead->id]], 201);
    }
}
