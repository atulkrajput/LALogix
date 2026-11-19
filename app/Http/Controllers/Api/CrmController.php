<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\DemoBooking;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin dashboard data: stats, activities, demo bookings and follow-ups.
 * Ported from old/src/app/api/{admin,activity,demo,followups}.
 */
class CrmController extends Controller
{
    /** GET /api/admin/stats */
    public function stats(): JsonResponse
    {
        $count = fn (string $status) => Lead::where('status', $status)->count();

        $totalLeads = Lead::count();
        $newLeads = $count('new');
        $contacted = $count('contacted');
        $demoScheduled = $count('demo_scheduled');
        $converted = $count('converted') + $count('paid'); // "paid" is the new name for a won lead
        $lost = $count('lost');

        $pct = fn (int $n) => $totalLeads > 0 ? number_format($n / $totalLeads * 100, 1, '.', '') : '0';

        return response()->json([
            'totalLeads' => $totalLeads,
            'newLeads' => $newLeads,
            'contacted' => $contacted,
            'demoScheduled' => $demoScheduled,
            'converted' => $converted,
            'lost' => $lost,
            'upcomingDemos' => DemoBooking::where('status', 'scheduled')->count(),
            'recentActivities' => Activity::count(),
            'activeCampaigns' => Campaign::where('status', 'active')->count(),
            'pendingFollowups' => $this->pendingFollowups()->count(),
            'conversionRate' => $pct($converted),
            'demoRate' => $pct($demoScheduled + $converted),
            'responseRate' => $pct($contacted + $demoScheduled + $converted),
            'leadsBySource' => Lead::selectRaw('source, count(*) as count')->groupBy('source')->get()
                ->map(fn ($r) => ['source' => $r->source, 'count' => (int) $r->count]),
            'leadsByProduct' => Lead::selectRaw('product_interest, count(*) as count')->groupBy('product_interest')->get()
                ->map(fn ($r) => ['product' => $r->product_interest, 'count' => (int) $r->count]),
        ]);
    }

    /** GET /api/admin/activities — latest 100. */
    public function activities(): JsonResponse
    {
        $activities = Activity::with('lead:id,name,email')->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['activities' => $activities]);
    }

    /** POST /api/activity — log an activity on a lead. */
    public function storeActivity(Request $request): JsonResponse
    {
        $leadId = $request->input('lead_id');
        $type = $request->input('type');

        if (! $leadId || ! $type) {
            return response()->json(['error' => 'lead_id and type are required'], 400);
        }

        $valid = ['call', 'email', 'whatsapp', 'meeting'];
        if (! in_array($type, $valid, true)) {
            return response()->json(['error' => 'type must be one of: '.implode(', ', $valid)], 400);
        }

        Lead::findOrFail((int) $leadId);

        $activity = Activity::create([
            'lead_id' => (int) $leadId,
            'type' => $type,
            'notes' => $request->input('notes') ?: null,
        ]);

        return response()->json(['success' => true, 'activity' => $activity->fresh()], 201);
    }

    /** GET /api/demo — demo bookings. */
    public function demos(Request $request): JsonResponse
    {
        $query = DemoBooking::with('lead:id,name,email,phone,organization')->orderBy('scheduled_date');

        $status = $request->query('status');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        return response()->json(['bookings' => $query->get()]);
    }

    /** PUT /api/demo/{id} */
    public function updateDemo(Request $request, int $id): JsonResponse
    {
        $booking = DemoBooking::findOrFail($id);
        $data = [];

        if ($status = $request->input('status')) {
            $data['status'] = $status;
        }
        if ($request->exists('meeting_link')) {
            $data['meeting_link'] = $request->input('meeting_link');
        }
        if ($request->exists('notes')) {
            $data['notes'] = $request->input('notes');
        }

        $booking->update($data);

        return response()->json($booking->fresh());
    }

    /** GET /api/followups?status=pending|completed|all */
    public function followups(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = match ($status) {
            'pending' => $this->pendingFollowups(),
            'completed' => Lead::whereNotNull('next_followup_date')->where('followup_status', 'completed'),
            default => Lead::whereNotNull('next_followup_date'),
        };

        $leads = $query
            ->select(['id', 'name', 'email', 'phone', 'organization', 'city', 'status', 'next_followup_date', 'followup_status', 'score'])
            ->with('tags')
            ->orderBy('next_followup_date')
            ->get();

        return response()->json(['followups' => $leads]);
    }

    private function pendingFollowups()
    {
        return Lead::whereNotNull('next_followup_date')
            ->where(fn ($q) => $q->where('followup_status', 'pending')->orWhereNull('followup_status'));
    }
}
