<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WorkItem;
use App\Services\Billing\InvoicePdf;
use App\Services\Billing\InvoiceService;
use App\Services\EmailService;
use App\Support\GstStates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['product:id,name', 'client:id,name,company'])
            ->where('type', $request->query('type') === 'credit_note' ? Invoice::TYPE_CREDIT_NOTE : Invoice::TYPE_INVOICE);

        foreach (['product_id', 'client_id'] as $filter) {
            if ($value = $request->query($filter)) {
                $query->where($filter, $value);
            }
        }
        if ($from = $request->query('from')) {
            $query->whereDate('invoice_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('invoice_date', '<=', $to);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('bill_name', 'like', "%{$q}%"));
        }
        $this->applyStatus($query, (string) $request->query('status', 'all'));

        // Totals over everything matching the filters (issued documents only).
        $summary = (clone $query)->where('status', 'issued')->reorder()->selectRaw(
            'COUNT(*) as count, COALESCE(SUM(taxable_total),0) as taxable, COALESCE(SUM(cgst_total + sgst_total + igst_total),0) as tax,
             COALESCE(SUM(grand_total),0) as total, COALESCE(SUM(amount_paid),0) as received,
             COALESCE(SUM(grand_total - amount_paid - amount_credited),0) as outstanding'
        )->first();

        $page = $query->orderByDesc('invoice_date')->orderByDesc('id')->paginate(50);

        return response()->json([
            'invoices' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
            'summary' => collect($summary?->getAttributes() ?? [])->map(fn ($v) => round((float) $v, 2)),
        ]);
    }

    /** Lookups for the invoice builder. */
    public function meta(): JsonResponse
    {
        return response()->json([
            'profile' => collect(BusinessProfile::current()->toArray())
                ->only(['stateCode', 'gstin', 'lutNumber', 'paymentTermsDays', 'roundOffTotal', 'invoiceNotes', 'invoiceTerms']),
            'products' => Product::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'kind', 'invoice_prefix', 'credit_note_prefix', 'default_sac', 'default_gst_rate']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company', 'gstin', 'state_code', 'country', 'email']),
            'states' => GstStates::options(),
            'gstRates' => SettingsController::GST_RATES,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['items', 'payments', 'product', 'client', 'originalInvoice:id,number,invoice_date,grand_total,amount_credited',
            'creditNotes' => fn ($q) => $q->orderBy('id')->select('id', 'original_invoice_id', 'number', 'status', 'invoice_date', 'grand_total')])
            ->findOrFail($id);

        return response()->json([
            'invoice' => $invoice,
            'unbilledWork' => $invoice->isDraft() && ! $invoice->isCreditNote()
                ? WorkItem::where('client_id', $invoice->client_id)->where(fn ($q) => $q->whereNull('invoice_id')->orWhere('invoice_id', $invoice->id))->latest('id')->get()
                : [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $invoice = $this->service->saveDraft(null, $this->validated($request));

        return response()->json(['invoice' => $invoice], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        return response()->json(['invoice' => $this->service->saveDraft($invoice, $this->validated($request, $invoice))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->deleteDraft(Invoice::findOrFail($id));

        return response()->json(['success' => true]);
    }

    public function issue(int $id): JsonResponse
    {
        return response()->json(['invoice' => $this->service->issue(Invoice::findOrFail($id))]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255'], ['reason.required' => 'Give a reason for cancelling.']);

        return response()->json(['invoice' => $this->service->cancel(Invoice::findOrFail($id), $data['reason'])]);
    }

    public function creditNote(int $id): JsonResponse
    {
        $note = $this->service->draftCreditNote(Invoice::with('items')->findOrFail($id));

        return response()->json(['invoice' => $note], 201);
    }

    public function storePayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0',
            'tds_amount' => 'nullable|numeric|min:0',
            'method' => ['required', Rule::in(['bank', 'upi', 'cash', 'cheque', 'card', 'other'])],
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:255',
        ]);
        $this->service->addPayment(Invoice::findOrFail($id), $data);

        return $this->show($id);
    }

    public function destroyPayment(int $id, int $paymentId): JsonResponse
    {
        $this->service->deletePayment(Payment::where('invoice_id', $id)->findOrFail($paymentId));

        return $this->show($id);
    }

    public function email(Request $request, int $id, EmailService $mailer): JsonResponse
    {
        $invoice = Invoice::with(['items', 'product', 'client'])->findOrFail($id);
        if ($invoice->status !== 'issued') {
            return response()->json(['error' => 'Only issued documents can be e-mailed.'], 422);
        }
        $data = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $result = $mailer->send(
            $data['to'],
            $data['subject'],
            nl2br(e($data['message'])),
            null,
            [['filename' => InvoicePdf::filename($invoice), 'content' => base64_encode(InvoicePdf::render($invoice))]],
        );
        if (! $result['success']) {
            return response()->json(['error' => $result['error'] ?? 'Failed to send email'], 502);
        }
        $invoice->update(['emailed_at' => now()]);

        return response()->json(['success' => true, 'stub' => ! config('lalogix.resend_api_key')]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Invoice $invoice = null): array
    {
        return $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'place_of_supply' => ['nullable', Rule::in([...array_keys(GstStates::ALL), GstStates::OUTSIDE_INDIA])],
            'export_with_lut' => 'boolean',
            'reverse_charge' => 'boolean',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'reason' => [$invoice?->isCreditNote() ? 'required' : 'nullable', 'string', 'max:255'],
            'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:1000',
            'items.*.sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'items.*.quantity' => 'required|numeric|gt:0|max:9999999',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.rate' => 'required|numeric|min:0|max:9999999999',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
            'items.*.work_item_id' => 'nullable|integer|exists:work_items,id',
        ], [
            'items.required' => 'Add at least one line.',
            'items.*.description.required' => 'Each line needs a description.',
            'items.*.quantity.gt' => 'Quantity must be more than zero.',
            'items.*.sac.regex' => 'SAC/HSN codes are 4 to 8 digits.',
            'reason.required' => 'Give the reason for this credit note.',
        ]);
    }

    private function applyStatus(Builder $query, string $status): void
    {
        $due = 'grand_total - amount_paid - amount_credited';
        match ($status) {
            'draft', 'cancelled' => $query->where('status', $status),
            'issued' => $query->where('status', 'issued'),
            'unpaid' => $query->where('status', 'issued')->where('amount_paid', 0)->where('amount_credited', 0),
            'partial' => $query->where('status', 'issued')->whereRaw("{$due} > 0.004")
                ->where(fn ($q) => $q->where('amount_paid', '>', 0)->orWhere('amount_credited', '>', 0)),
            'paid' => $query->where('status', 'issued')->whereRaw("{$due} <= 0.004"),
            'outstanding' => $query->where('status', 'issued')->whereRaw("{$due} > 0.004"),
            'overdue' => $query->where('status', 'issued')->whereRaw("{$due} > 0.004")->whereDate('due_date', '<', now()->toDateString()),
            default => null,
        };
    }
}
