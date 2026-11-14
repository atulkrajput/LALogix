<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadTag;
use App\Support\LeadAttribution;
use App\Services\AutomationService;
use App\Services\EmailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ported from old/src/app/api/leads/** */
class LeadController extends Controller
{
    /** POST /api/leads — create a lead (public). */
    public function store(Request $request, EmailService $email, AutomationService $automation): JsonResponse
    {
        $name = $request->input('name');
        $mail = $request->input('email');

        if (! $name || ! $mail) {
            return response()->json(['error' => 'Name and email are required'], 400);
        }

        $productInterest = $request->input('product_interest') ?: 'general';
        $source = $request->input('source') ?: 'website';

        $lead = Lead::create([
            'name' => $name,
            'organization' => $request->input('organization') ?: null,
            'city' => $request->input('city') ?: null,
            'phone' => $request->input('phone') ?: null,
            'email' => $mail,
            'message' => $request->input('message') ?: null,
            'product_interest' => $productInterest,
            'source' => $source,
            'status' => 'new',
            ...LeadAttribution::fromRequest($request),
        ]);

        $email->notifySales(
            "New Lead: {$name} — ".($request->input('product_interest') ?: 'General'),
            'New Lead Received',
            [
                'Name' => $name,
                'Email' => $mail,
                'Phone' => $request->input('phone'),
                'Organization' => $request->input('organization'),
                'City' => $request->input('city'),
                'Product Interest' => $request->input('product_interest') ?: 'General',
                'Message' => $request->input('message'),
                'Source' => $source,
            ],
        );

        // Run "new_lead" automation rules after the response is sent.
        dispatch(fn () => $automation->run('new_lead', $lead->id))->afterResponse();

        return response()->json(['success' => true, 'lead' => ['id' => $lead->id]], 201);
    }

    /** GET /api/leads — list with filters + pagination. */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = max(1, (int) $request->query('limit', 50));

        $query = $this->filtered($request);

        if ($search = $request->query('search')) {
            $query->where(function (Builder $q) use ($search) {
                foreach (['name', 'email', 'organization', 'phone', 'city'] as $col) {
                    $q->orWhere($col, 'like', "%{$search}%");
                }
            });
        }
        if ($state = $request->query('state')) {
            $query->where('state', 'like', "%{$state}%");
        }
        $source = $request->query('source');
        if ($source && $source !== 'all') {
            $query->where('source', $source);
        }

        $total = (clone $query)->count();

        $leads = $query
            ->orderBy('created_at', $request->query('sort') === 'asc' ? 'asc' : 'desc')
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->withCount(['demoBookings', 'activities'])
            ->with('tags')
            ->get();

        return response()->json(['leads' => $leads, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    }

    /** GET /api/leads/{id} */
    public function show(int $id): JsonResponse
    {
        $lead = Lead::with([
            'demoBookings' => fn ($q) => $q->orderByDesc('created_at'),
            'activities' => fn ($q) => $q->orderByDesc('created_at'),
            'tags',
            'whatsappLogs' => fn ($q) => $q->orderByDesc('created_at')->limit(20),
        ])->find($id);

        if (! $lead) {
            return response()->json(['error' => 'Lead not found'], 404);
        }

        return response()->json($lead);
    }

    /** PUT /api/leads/{id} — body uses camelCase keys, as in the old API. */
    public function update(Request $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $data = [];

        foreach (['status', 'name', 'email', 'productInterest' => 'product_interest'] as $in => $col) {
            $in = is_int($in) ? $col : $in;
            if ($request->filled($in)) {
                $data[$col] = $request->input($in);
            }
        }
        foreach (['organization', 'city', 'state', 'phone', 'message', 'score', 'followupStatus' => 'followup_status'] as $in => $col) {
            $in = is_int($in) ? $col : $in;
            if ($request->exists($in)) {
                $data[$col] = $request->input($in);
            }
        }
        if ($request->exists('nextFollowupDate')) {
            $value = $request->input('nextFollowupDate');
            $data['next_followup_date'] = $value ? Carbon::parse($value) : null;
        }

        $lead->update($data);

        return response()->json($lead->fresh());
    }

    /** PUT /api/leads/{id}/followup */
    public function followup(Request $request, int $id): JsonResponse
    {
        $lead = Lead::findOrFail($id);
        $data = [];

        if ($date = $request->input('next_followup_date')) {
            $data['next_followup_date'] = Carbon::parse($date);
        }
        if ($status = $request->input('followup_status')) {
            $data['followup_status'] = $status;
        }

        $lead->update($data);

        return response()->json($lead->fresh());
    }

    /** POST /api/leads/{id}/score — adjust score by `delta`. */
    public function score(Request $request, int $id): JsonResponse
    {
        $delta = $request->input('delta');
        if (! is_int($delta) && ! is_float($delta)) {
            return response()->json(['error' => 'delta (number) is required'], 400);
        }

        $lead = Lead::findOrFail($id);
        $lead->increment('score', (int) $delta);

        Activity::create([
            'lead_id' => $lead->id,
            'type' => 'score',
            'notes' => 'Score '.($delta > 0 ? '+' : '').$delta.': '.($request->input('reason') ?: 'manual adjustment'),
        ]);

        return response()->json(['score' => $lead->fresh()->score]);
    }

    /** POST /api/leads/{id}/tags */
    public function addTag(Request $request, int $id): JsonResponse
    {
        $tag = $request->input('tag_name');
        if (! $tag) {
            return response()->json(['error' => 'tag_name is required'], 400);
        }

        Lead::findOrFail($id);

        try {
            $leadTag = LeadTag::create(['lead_id' => $id, 'tag_name' => $tag]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) { // duplicate key
                return response()->json(['error' => 'Tag already exists on this lead'], 409);
            }
            throw $e;
        }

        return response()->json($leadTag, 201);
    }

    /** DELETE /api/leads/{id}/tags */
    public function removeTag(Request $request, int $id): JsonResponse
    {
        $tag = $request->input('tag_name');
        if (! $tag) {
            return response()->json(['error' => 'tag_name is required'], 400);
        }

        LeadTag::where('lead_id', $id)->where('tag_name', $tag)->delete();

        return response()->json(['success' => true]);
    }

    /** GET /api/leads/export — CSV download. */
    public function export(Request $request): StreamedResponse
    {
        $leads = $this->filtered($request)->with('tags')->orderByDesc('created_at')->get();

        return response()->streamDownload(function () use ($leads) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'name', 'email', 'phone', 'organization', 'city', 'state', 'product_interest', 'source', 'status', 'score', 'tags', 'created_at']);
            foreach ($leads as $l) {
                fputcsv($out, [
                    $l->id, $l->name, $l->email, $l->phone, $l->organization, $l->city, $l->state,
                    $l->product_interest, $l->source, $l->status, $l->score,
                    $l->tags->pluck('tag_name')->implode(';'),
                    $l->created_at?->toISOString(),
                ]);
            }
            fclose($out);
        }, 'leads-export-'.(int) (microtime(true) * 1000).'.csv', ['Content-Type' => 'text/csv']);
    }

    /** POST /api/leads/import — bulk import (rows from a parsed CSV). */
    public function import(Request $request): JsonResponse
    {
        $rows = $request->input('leads');
        if (! is_array($rows) || count($rows) === 0) {
            return response()->json(['error' => 'leads array is required'], 400);
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $mail = $row['email'] ?? null;
            $contact = $row['contact_person'] ?? null;

            if (! $mail || ! $contact) {
                $skipped++;
                $errors[] = 'Skipped: missing email or contact_person';
                continue;
            }

            try {
                Lead::create([
                    'name' => $contact,
                    'organization' => ($row['school_name'] ?? null) ?: null,
                    'city' => ($row['city'] ?? null) ?: null,
                    'state' => ($row['state'] ?? null) ?: null,
                    'phone' => ($row['phone'] ?? null) ?: null,
                    'email' => $mail,
                    'product_interest' => 'school',
                    'source' => ($row['source'] ?? null) ?: 'imported',
                    'status' => 'new',
                ]);
                $imported++;
            } catch (\Throwable) {
                $skipped++;
                $errors[] = "Skipped: {$mail} (duplicate or error)";
            }
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
        ]);
    }

    /** Shared status / city / tag filters. */
    private function filtered(Request $request): Builder
    {
        $query = Lead::query();

        $status = $request->query('status');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }
        if ($city = $request->query('city')) {
            $query->where('city', 'like', "%{$city}%");
        }
        if ($tag = $request->query('tag')) {
            $query->whereHas('tags', fn ($q) => $q->where('tag_name', $tag));
        }

        return $query;
    }
}
