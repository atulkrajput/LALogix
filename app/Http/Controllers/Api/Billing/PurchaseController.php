<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Purchase;
use App\Models\Vendor;
use App\Services\Billing\InvoiceCalculator;
use App\Support\Gstin;
use App\Support\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Purchase bills & expenses, with the GST paid and the input tax credit (ITC) it gives. */
class PurchaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Purchase::with(['vendor:id,name,gstin', 'product:id,name']);
        if ($from = $request->query('from')) {
            $query->whereDate('bill_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('bill_date', '<=', $to);
        }
        foreach (['category', 'vendor_id', 'payment_status', 'product_id'] as $f) {
            if ($v = $request->query($f)) {
                $query->where($f, $v);
            }
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('bill_number', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', "%{$q}%")));
        }

        $all = (clone $query)->get(['taxable_value', 'cgst', 'sgst', 'igst', 'total', 'itc_eligible', 'payment_status']);
        $page = $query->orderByDesc('bill_date')->orderByDesc('id')->paginate(50);

        return response()->json([
            'purchases' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
            'summary' => [
                'count' => $all->count(),
                'taxable' => round($all->sum('taxable_value'), 2),
                'gst' => round($all->sum(fn ($p) => $p->cgst + $p->sgst + $p->igst), 2),
                'itc' => round($all->where('itc_eligible', true)->sum(fn ($p) => $p->cgst + $p->sgst + $p->igst), 2),
                'total' => round($all->sum('total'), 2),
                'unpaid' => round($all->where('payment_status', 'unpaid')->sum('total'), 2),
            ],
            'categories' => Purchase::CATEGORIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $purchase = new Purchase();
        $this->fillAndSave($purchase, $request);

        return response()->json(['purchase' => $purchase->fresh(['vendor', 'product'])], 201);
    }

    /** POST (not PUT) so a replacement attachment can be sent as multipart. */
    public function update(Request $request, int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);
        $this->fillAndSave($purchase, $request);

        return response()->json(['purchase' => $purchase->fresh(['vendor', 'product'])]);
    }

    public function destroy(int $id): JsonResponse
    {
        $purchase = Purchase::findOrFail($id);
        if ($purchase->attachment_path) {
            Storage::disk('local')->delete($purchase->attachment_path);
        }
        $purchase->delete();

        return response()->json(['success' => true]);
    }

    /** Web route (session auth): the bill's file, shown inline. */
    public function attachment(int $id): StreamedResponse
    {
        $purchase = Purchase::findOrFail($id);
        abort_unless($purchase->attachment_path && Storage::disk('local')->exists($purchase->attachment_path), 404);

        return Storage::disk('local')->response($purchase->attachment_path, $purchase->attachment_name, [], 'inline');
    }

    public function vendors(): JsonResponse
    {
        return response()->json(['vendors' => Vendor::withCount('purchases')->orderBy('name')->get(), 'categories' => Purchase::CATEGORIES]);
    }

    private function fillAndSave(Purchase $purchase, Request $request): void
    {
        $request->merge(['vendor_gstin' => Gstin::normalize($request->input('vendor_gstin'))]);
        $bool = fn ($k, $d) => $request->has($k) ? filter_var($request->input($k), FILTER_VALIDATE_BOOLEAN) : $d;

        $data = $request->validate([
            'vendor_id' => 'nullable|integer|exists:vendors,id',
            'vendor_name' => 'nullable|required_without:vendor_id|string|max:255',
            'vendor_gstin' => ['nullable', 'string', function ($a, $v, $fail) {
                if ($v && ! Gstin::isValid($v)) {
                    $fail('That supplier GSTIN is not valid (format or check digit).');
                }
            }],
            'vendor_country' => 'nullable|string|max:100',
            'category' => ['required', Rule::in(array_keys(Purchase::CATEGORIES))],
            'product_id' => 'nullable|integer|exists:products,id',
            'bill_number' => 'nullable|string|max:50',
            'bill_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'place_of_supply' => ['nullable', Rule::in([...array_keys(GstStates::ALL), GstStates::OUTSIDE_INDIA])],
            'taxable_value' => 'required|numeric|min:0|max:9999999999',
            'gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
            'tax_mode' => ['nullable', Rule::in(['auto', 'manual'])],
            'cgst' => 'nullable|numeric|min:0',
            'sgst' => 'nullable|numeric|min:0',
            'igst' => 'nullable|numeric|min:0',
            'itc_note' => 'nullable|string|max:255',
            'payment_status' => ['nullable', Rule::in(['paid', 'unpaid'])],
            'paid_on' => 'nullable|date',
            'payment_method' => ['nullable', Rule::in(['bank', 'upi', 'cash', 'cheque', 'card', 'other'])],
            'attachment' => 'nullable|file|max:5120|mimes:pdf,jpg,jpeg,png,webp',
            'remove_attachment' => 'nullable',
        ], [
            'vendor_name.required_without' => 'Choose or type the supplier.',
            'attachment.mimes' => 'Attach a PDF or an image (JPG, PNG).',
            'attachment.max' => 'The attachment must be smaller than 5 MB.',
        ]);

        // Supplier: chosen, or found/created from name + GSTIN.
        $vendor = ! empty($data['vendor_id']) ? Vendor::find($data['vendor_id']) : null;
        if (! $vendor) {
            $vendor = ($data['vendor_gstin'] ? Vendor::where('gstin', $data['vendor_gstin'])->first() : null)
                ?? Vendor::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['vendor_name']))])->first()
                ?? Vendor::create([
                    'name' => trim($data['vendor_name']),
                    'gstin' => $data['vendor_gstin'],
                    'state_code' => Gstin::stateCode($data['vendor_gstin']),
                    'country' => trim($data['vendor_country'] ?? '') ?: 'India',
                    'default_category' => $data['category'],
                ]);
        }
        $gstin = $data['vendor_gstin'] ?: $vendor->gstin;

        // Same bill twice?
        if (! empty($data['bill_number'])) {
            $dupe = Purchase::where('vendor_id', $vendor->id)->where('bill_number', $data['bill_number'])
                ->when($purchase->exists, fn ($q) => $q->whereKeyNot($purchase->id))->exists();
            if ($dupe) {
                abort(response()->json(['error' => "Bill {$data['bill_number']} from {$vendor->name} is already recorded."], 422));
            }
        }

        // Where the supply happens decides CGST+SGST vs IGST; foreign suppliers are imports (reverse charge).
        $ourState = BusinessProfile::current()->state_code;
        $foreign = strcasecmp($vendor->country ?: 'India', 'India') !== 0;
        $vendorState = Gstin::stateCode($gstin) ?: $vendor->state_code;
        $supplyType = match (true) {
            $foreign => 'import',
            (float) $data['gst_rate'] === 0.0 => 'none',
            $vendorState && $ourState && $vendorState !== $ourState => 'inter',
            ($data['place_of_supply'] ?? null) && $ourState && $data['place_of_supply'] !== $ourState => 'inter',
            default => 'intra',
        };
        $reverseCharge = $bool('reverse_charge', $foreign);

        if (($data['tax_mode'] ?? 'auto') === 'manual') {
            $tax = ['cgst' => round((float) ($data['cgst'] ?? 0), 2), 'sgst' => round((float) ($data['sgst'] ?? 0), 2), 'igst' => round((float) ($data['igst'] ?? 0), 2)];
        } else {
            // Imports are taxed as IGST; a 0% rate simply gives no tax.
            $calc = InvoiceCalculator::calculate([['quantity' => 1, 'rate' => $data['taxable_value'], 'gst_rate' => $data['gst_rate']]],
                in_array($supplyType, ['inter', 'import'], true) ? 'inter' : 'intra', false, false);
            $tax = ['cgst' => $calc['totals']['cgst_total'], 'sgst' => $calc['totals']['sgst_total'], 'igst' => $calc['totals']['igst_total']];
        }
        $taxTotal = $tax['cgst'] + $tax['sgst'] + $tax['igst'];
        $paid = ($data['payment_status'] ?? 'paid') === 'paid';

        $purchase->fill([
            'vendor_id' => $vendor->id,
            'product_id' => $data['product_id'] ?? null,
            'category' => $data['category'],
            'bill_number' => $data['bill_number'] ?? null,
            'bill_date' => $data['bill_date'],
            'description' => $data['description'] ?? null,
            'vendor_gstin' => $gstin,
            'place_of_supply' => $data['place_of_supply'] ?? ($foreign ? GstStates::OUTSIDE_INDIA : $ourState),
            'supply_type' => $supplyType,
            'reverse_charge' => $reverseCharge,
            'taxable_value' => round((float) $data['taxable_value'], 2),
            'gst_rate' => $data['gst_rate'],
            ...$tax,
            // Under reverse charge the GST is paid to the government, not to the supplier.
            'total' => round((float) $data['taxable_value'] + ($reverseCharge ? 0 : $taxTotal), 2),
            // ITC needs a GST invoice: a supplier GSTIN, or an import paid under reverse charge.
            'itc_eligible' => $bool('itc_eligible', $taxTotal > 0 && ($gstin || $reverseCharge)),
            'itc_note' => $data['itc_note'] ?? null,
            'payment_status' => $paid ? 'paid' : 'unpaid',
            'paid_on' => $paid ? ($data['paid_on'] ?? $data['bill_date']) : null,
            'payment_method' => $paid ? ($data['payment_method'] ?? null) : null,
        ]);

        if ($request->hasFile('attachment')) {
            if ($purchase->attachment_path) {
                Storage::disk('local')->delete($purchase->attachment_path);
            }
            $file = $request->file('attachment');
            $purchase->attachment_path = $file->store('purchases/'.date('Y/m'), 'local');
            $purchase->attachment_name = mb_substr($file->getClientOriginalName(), 0, 255);
        } elseif ($bool('remove_attachment', false) && $purchase->attachment_path) {
            Storage::disk('local')->delete($purchase->attachment_path);
            $purchase->attachment_path = $purchase->attachment_name = null;
        }

        $purchase->save();
    }
}
