<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\LeadAttribution;
use App\Support\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** POST /api/lead-events — logs clicks such as the floating WhatsApp button (no personal data). */
class LeadEventController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:whatsapp_click'],
            'product' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:50'],
            'page_url' => ['nullable', 'string', 'max:500'],
        ]);

        $product = $data['product'] ?? null;
        $attribution = LeadAttribution::fromRequest($request);

        DB::table('lead_events')->insert([
            'type' => $data['type'],
            'product' => in_array($product, Site::leadKeys(), true) ? $product : null,
            'location' => isset($data['location']) ? preg_replace('/[^a-z0-9_-]/i', '', $data['location']) : null,
            'page_url' => preg_match('#^https?://#i', (string) ($data['page_url'] ?? '')) ? $data['page_url'] : null,
            'utm_source' => $attribution['utm_source'] ?? null,
            'utm_medium' => $attribution['utm_medium'] ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            // Hashed so clicks from one visitor can be grouped without storing the IP.
            'ip_hash' => hash('sha256', $request->ip().'|'.config('app.key')),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true], 201);
    }
}
