<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\WorkItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Billable work logged against clients, later pulled into invoices. */
class WorkItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WorkItem::with(['client:id,name,company', 'product:id,name', 'invoice:id,number,status'])
            ->orderByDesc('work_date')->orderByDesc('id');

        if ($clientId = $request->query('client_id')) {
            $query->where('client_id', $clientId);
        }
        match ($request->query('status')) {
            'unbilled' => $query->whereNull('invoice_id'),
            'billed' => $query->whereNotNull('invoice_id'),
            default => null,
        };

        return response()->json(['workItems' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $item = WorkItem::create($this->validated($request));

        return response()->json(['workItem' => $item->fresh(['client:id,name,company', 'product:id,name'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = WorkItem::findOrFail($id);
        if ($item->invoice_id) {
            return response()->json(['error' => 'This work is already on an invoice. Edit the invoice instead.'], 422);
        }
        $item->update($this->validated($request));

        return response()->json(['workItem' => $item->fresh(['client:id,name,company', 'product:id,name'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $item = WorkItem::findOrFail($id);
        if ($item->invoice_id) {
            return response()->json(['error' => 'This work is already on an invoice.'], 422);
        }
        $item->delete();

        return response()->json(['success' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'quantity' => 'required|numeric|gt:0|max:9999999',
            'unit' => 'nullable|string|max:10',
            'rate' => 'required|numeric|min:0|max:9999999999',
            'gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
            'work_date' => 'nullable|date',
        ], ['sac.regex' => 'SAC/HSN codes are 4 to 8 digits.']);
        $data['unit'] = strtoupper(trim($data['unit'] ?? '')) ?: 'NOS';

        return $data;
    }
}
