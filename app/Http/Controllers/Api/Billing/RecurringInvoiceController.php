<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\RecurringInvoice;
use App\Services\Billing\RecurringInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecurringInvoiceController extends Controller
{
    public function __construct(private RecurringInvoiceService $service) {}

    public function index(): JsonResponse
    {
        $schedules = RecurringInvoice::with(['client:id,name,company,email', 'product:id,name'])->orderByRaw("status = 'active' desc")->orderBy('next_run_date')->get();
        $active = $schedules->where('status', 'active');
        // Monthly equivalent of active schedules (before GST).
        $monthly = $active->sum(fn (RecurringInvoice $r) => $r->amount() / (RecurringInvoice::FREQUENCIES[$r->frequency] ?? 1));

        return response()->json([
            'schedules' => $schedules->map(fn (RecurringInvoice $r) => [...$r->toArray(), 'amount' => $r->amount(),
                'nextPeriodLabel' => $this->service->periodLabel($r, $r->next_run_date)])->values(),
            'summary' => [
                'active' => $active->count(),
                'monthly_value' => round($monthly, 2),
                'yearly_value' => round($monthly * 12, 2),
                'due_next_30_days' => $active->filter(fn ($r) => $r->next_run_date->lte(now()->addDays(30)))->count(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $r = RecurringInvoice::with(['client', 'product'])->findOrFail($id);

        return response()->json([
            'schedule' => [...$r->toArray(), 'amount' => $r->amount()],
            'invoices' => $r->invoices()->orderByDesc('recurring_period')->limit(50)->get(['id', 'number', 'status', 'invoice_date', 'recurring_period', 'grand_total', 'amount_paid', 'amount_credited', 'due_date', 'type']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $r = RecurringInvoice::create($data + ['next_run_date' => $data['start_date'], 'status' => 'active']);
        $this->service->runDue(null, $r); // a start date of today or earlier creates the first invoice now

        return response()->json(['schedule' => $r->fresh(['client', 'product'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $r = RecurringInvoice::findOrFail($id);
        $data = $this->validated($request, $r);
        // Moving the start date only matters before the first invoice.
        if ($r->invoices_created === 0) {
            $data['next_run_date'] = $data['start_date'];
        }
        $r->update($data);

        return response()->json(['schedule' => $r->fresh(['client', 'product'])]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused'])]]);
        $r = RecurringInvoice::findOrFail($id);
        $update = ['status' => $data['status']];
        // Resuming after a pause skips the periods that passed while paused.
        if ($data['status'] === 'active' && $r->status === 'paused') {
            $next = $r->next_run_date->copy();
            while ($next->lt(now()->startOfDay())) {
                $next = $r->periodAfter($next);
            }
            $update['next_run_date'] = $next->toDateString();
        }
        $r->update($update);

        return $this->show($id);
    }

    public function destroy(int $id): JsonResponse
    {
        RecurringInvoice::findOrFail($id)->delete(); // invoices already created stay (link set to null)

        return response()->json(['success' => true]);
    }

    /** Create anything due now (also runs automatically). */
    public function run(): JsonResponse
    {
        return response()->json(['result' => $this->service->runDue()]);
    }

    /** The secret URL an external cron service can call; created on first request. */
    public function cronUrl(Request $request): JsonResponse
    {
        $profile = BusinessProfile::current();
        if (! $profile->cron_token || $request->boolean('regenerate')) {
            $profile->forceFill(['cron_token' => Str::random(40)])->save();
        }

        return response()->json(['url' => url('/cron/recurring/'.$profile->cron_token)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?RecurringInvoice $r = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'product_id' => 'required|integer|exists:products,id',
            'client_id' => 'required|integer|exists:clients,id',
            'frequency' => ['required', Rule::in(array_keys(RecurringInvoice::FREQUENCIES))],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'auto_issue' => 'boolean',
            'auto_email' => 'boolean',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:50',
            'items.*.description' => 'required|string|max:1000',
            'items.*.sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
        ], ['items.required' => 'Add at least one line.']);

        if (! empty($data['auto_email'])) {
            $data['auto_issue'] = true; // only issued invoices are e-mailed
            if (! Client::whereKey($data['client_id'])->value('email')) {
                abort(response()->json(['error' => 'The client has no e-mail address. Add one to the client, or turn off automatic e-mail.'], 422));
            }
        }
        $data['items'] = array_map(fn ($i) => [
            'description' => $i['description'], 'sac' => $i['sac'] ?? null, 'quantity' => (float) $i['quantity'],
            'unit' => $i['unit'] ?? 'NOS', 'rate' => (float) $i['rate'], 'discount' => (float) ($i['discount'] ?? 0), 'gst_rate' => (float) $i['gst_rate'],
        ], $data['items']);
        $data['start_date'] = Carbon::parse($data['start_date'])->toDateString();

        return $data;
    }
}
