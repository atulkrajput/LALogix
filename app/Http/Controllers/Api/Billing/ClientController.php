<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Support\Gstin;
use App\Support\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::withCount(['invoices', 'workItems'])->orderBy('name');

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('company', 'like', "%{$q}%")
                ->orWhere('gstin', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        $clients = $query->get();
        $outstanding = $this->outstandingByClient($clients->pluck('id')->all());
        $clients->each(fn (Client $c) => $c->setAttribute('outstanding', $outstanding[$c->id] ?? 0));

        return response()->json(['clients' => $clients]);
    }

    public function show(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $invoices = Invoice::with('product:id,name')->where('client_id', $id)
            ->orderByDesc('invoice_date')->orderByDesc('id')->get();
        $issued = $invoices->where('status', 'issued');

        return response()->json([
            'client' => $client,
            'invoices' => $invoices,
            'workItems' => $client->workItems()->with(['product:id,name', 'invoice:id,number,status'])->latest('id')->get(),
            'summary' => [
                'invoiced' => round($issued->where('type', Invoice::TYPE_INVOICE)->sum('grand_total'), 2),
                'credited' => round($issued->where('type', Invoice::TYPE_CREDIT_NOTE)->sum('grand_total'), 2),
                'received' => round($issued->sum('amount_paid'), 2),
                'outstanding' => round($issued->sum('balance_due'), 2),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = Client::create($this->validated($request));

        return response()->json(['client' => $client->fresh()], 201);
    }

    /**
     * Turn a CRM lead into a billing client (keeps the link and the ad campaign that
     * brought the lead, for the Marketing report). Returns the existing client if there is one.
     */
    public function fromLead(int $leadId): JsonResponse
    {
        $lead = Lead::findOrFail($leadId);
        $existing = Client::where('lead_id', $lead->id)->first()
            ?? ($lead->email ? Client::whereRaw('LOWER(email) = ?', [mb_strtolower($lead->email)])->first() : null);
        if ($existing) {
            $existing->fill(array_filter([
                'lead_id' => $existing->lead_id ?: $lead->id,
                'utm_source' => $existing->utm_source ?: $lead->utm_source,
                'utm_campaign' => $existing->utm_campaign ?: $lead->utm_campaign,
            ]))->save();

            return response()->json(['client' => $existing->fresh(), 'created' => false]);
        }

        $client = Client::create([
            'name' => $lead->name,
            'company' => $lead->organization,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'city' => $lead->city,
            'country' => 'India',
            'notes' => $lead->state ? "State from lead: {$lead->state}" : null,
            'lead_id' => $lead->id,
            'utm_source' => $lead->utm_source,
            'utm_campaign' => $lead->utm_campaign,
        ]);
        if (! in_array($lead->status, ['converted', 'lost'], true)) {
            $lead->update(['status' => 'converted']);
        }

        return response()->json(['client' => $client->fresh(), 'created' => true], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        $client->update($this->validated($request));

        return response()->json(['client' => $client->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $client = Client::findOrFail($id);
        if ($client->invoices()->exists()) {
            return response()->json(['error' => 'This client has invoices and cannot be deleted.'], 422);
        }
        $client->delete();

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge([
            'gstin' => Gstin::normalize($request->input('gstin')),
            'pan' => $request->filled('pan') ? strtoupper(trim($request->input('pan'))) : null,
            'country' => trim((string) $request->input('country')) ?: 'India',
        ]);
        $indian = strcasecmp($request->input('country'), 'India') === 0;
        if ($indian && Gstin::isValid($request->input('gstin'))) {
            // The GSTIN fixes the registered state, so the form's state field isn't needed.
            $request->merge(['state_code' => Gstin::stateCode($request->input('gstin'))]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'gstin' => ['nullable', 'string', function ($attr, $value, $fail) {
                if ($value && ! Gstin::isValid($value)) {
                    $fail('That GSTIN is not valid (format or check digit).');
                }
            }],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state_code' => [$indian ? 'required' : 'nullable', Rule::in(array_keys(GstStates::ALL))],
            'pincode' => 'nullable|string|max:10',
            'country' => 'required|string|max:100',
            'notes' => 'nullable|string|max:2000',
            'lead_id' => 'nullable|integer',
            'no_reminders' => 'sometimes|boolean',
        ], [
            'state_code.required' => 'Select the state (it decides the place of supply).',
            'pan.regex' => 'That PAN is not valid.',
        ]);

        if ($data['gstin'] ?? null) {
            if (! $indian) {
                abort(response()->json(['error' => 'A GSTIN can only be added for clients in India.'], 422));
            }
            // The GSTIN fixes the registered state; keep them consistent.
            $data['state_code'] = Gstin::stateCode($data['gstin']);
            $data['pan'] = $data['pan'] ?: Gstin::pan($data['gstin']);
        }
        if (! $indian) {
            $data['state_code'] = null;
        }

        return $data;
    }

    /** @return array<int, float> client_id => balance due */
    private function outstandingByClient(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        return Invoice::invoices()->where('status', 'issued')->whereIn('client_id', $ids)
            ->selectRaw('client_id, SUM(grand_total - amount_paid - amount_credited) as due')
            ->groupBy('client_id')->pluck('due', 'client_id')
            ->map(fn ($v) => max(0, round((float) $v, 2)))->all();
    }
}
