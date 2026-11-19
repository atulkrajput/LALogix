<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\LeadTag;
use App\Support\LeadAttribution;
use App\Services\LeadCaptureService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ported from old/src/app/api/leads/** */
class LeadController extends Controller
{
    /** POST /api/leads — create a lead (public): quiz, chat, exit popup, notify-me. */
    public function store(Request $request, LeadCaptureService $capture): JsonResponse
    {
        if (LeadCaptureService::isSpam($request)) {
            return response()->json(['success' => true], 201);
        }

        $lead = $capture->capture($request, 'form');

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

        // WhatsApp button clicks in the last 30 days (logged by POST /api/lead-events).
        $whatsappClicks = DB::table('lead_events')->where('type', 'whatsapp_click')->where('created_at', '>=', now()->subDays(30))->count();

        return response()->json(['leads' => $leads, 'total' => $total, 'page' => $page, 'limit' => $limit, 'whatsappClicks30d' => $whatsappClicks]);
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
            fputcsv($out, ['id', 'name', 'email', 'phone', 'organization', 'city', 'state', 'product_interest', 'business_type', 'size',
                'source', 'source_widget', 'status', 'score', 'page_url', 'utm_source', 'utm_medium', 'utm_campaign', 'message', 'tags', 'created_at']);
            foreach ($leads as $l) {
                fputcsv($out, array_map(fn ($v) => self::csvSafe($v), [
                    $l->id, $l->name, $l->email, $l->phone, $l->organization, $l->city, $l->state,
                    $l->product_interest, $l->business_type, $l->size, $l->source, $l->source_widget, $l->status, $l->score,
                    $l->page_url, $l->utm_source, $l->utm_medium, $l->utm_campaign, $l->message,
                    $l->tags->pluck('tag_name')->implode(';'),
                    $l->created_at?->toISOString(),
                ]));
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

    /** Stop spreadsheet apps from running visitor-typed text as a formula. */
    private static function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** Shared status / city / tag / product / source widget filters. */
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
        $product = $request->query('product');
        if ($product && $product !== 'all') {
            $query->where('product_interest', $product);
        }
        $widget = $request->query('source_widget');
        if ($widget && $widget !== 'all') {
            $widget === 'none' ? $query->whereNull('source_widget') : $query->where('source_widget', $widget);
        }

        return $query;
    }
}
