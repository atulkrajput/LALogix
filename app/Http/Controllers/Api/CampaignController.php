<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignLead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from old/src/app/api/campaigns/** */
class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Campaign::withCount('campaignLeads')->orderByDesc('created_at');

        foreach (['status', 'type'] as $filter) {
            $value = $request->query($filter);
            if ($value && $value !== 'all') {
                $query->where($filter, $value);
            }
        }

        return response()->json(['campaigns' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->input('name') || ! $request->input('type') || ! $request->input('product')) {
            return response()->json(['error' => 'name, type, and product are required'], 400);
        }

        $campaign = Campaign::create([
            'name' => $request->input('name'),
            'type' => $request->input('type'),
            'product' => $request->input('product'),
            'status' => $request->input('status') ?: 'active',
        ]);

        return response()->json($campaign->fresh(), 201);
    }

    public function show(int $id): JsonResponse
    {
        $campaign = Campaign::with('campaignLeads.lead:id,name,email,phone,status,city,organization')->find($id);

        if (! $campaign) {
            return response()->json(['error' => 'Campaign not found'], 404);
        }

        return response()->json($campaign);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $campaign = Campaign::findOrFail($id);
        $campaign->update(array_filter($request->only(['name', 'type', 'product', 'status'])));

        return response()->json($campaign->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        Campaign::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** POST /api/campaigns/{id}/leads — assign leads, skipping duplicates. */
    public function addLeads(Request $request, int $id): JsonResponse
    {
        $leadIds = $request->input('lead_ids');
        if (! is_array($leadIds) || count($leadIds) === 0) {
            return response()->json(['error' => 'lead_ids array is required'], 400);
        }

        Campaign::findOrFail($id);

        $added = 0;
        foreach ($leadIds as $leadId) {
            try {
                CampaignLead::create(['campaign_id' => $id, 'lead_id' => (int) $leadId]);
                $added++;
            } catch (\Throwable) {
                // duplicate or unknown lead — skip
            }
        }

        return response()->json(['success' => true, 'added' => $added]);
    }

    /** DELETE /api/campaigns/{id}/leads */
    public function removeLead(Request $request, int $id): JsonResponse
    {
        CampaignLead::where('campaign_id', $id)->where('lead_id', (int) $request->input('lead_id'))->delete();

        return response()->json(['success' => true]);
    }
}
