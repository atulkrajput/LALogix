<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Billing\QuotationPdf;
use App\Services\Billing\QuotationService;
use App\Services\EmailService;
use App\Support\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class QuotationController extends Controller
{
    public function __construct(private QuotationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Quotation::with(['product:id,name', 'client:id,name,company', 'invoice:id,number,status,type,grand_total,amount_paid,amount_credited,due_date']);
        foreach (['client_id', 'product_id'] as $f) {
            if ($v = $request->query($f)) {
                $query->where($f, $v);
            }
        }
        if ($from = $request->query('from')) {
            $query->whereDate('quote_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('quote_date', '<=', $to);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$q}%")->orWhere('bill_name', 'like', "%{$q}%"));
        }
        $status = (string) $request->query('status', 'all');
        if ($status === 'open') {
            $query->whereIn('status', ['draft', 'sent'])->where(fn ($w) => $w->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()));
        } elseif ($status === 'expired') {
            $query->whereIn('status', ['draft', 'sent'])->whereDate('valid_until', '<', now()->toDateString());
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $all = (clone $query)->get(['status', 'grand_total', 'taxable_total', 'valid_until']);
        $open = $all->filter(fn ($q) => in_array($q->display_status, ['draft', 'sent'], true));
        $won = $all->whereIn('status', ['accepted', 'converted']);
        $decided = $won->count() + $all->where('status', 'declined')->count();
        $page = $query->orderByDesc('quote_date')->orderByDesc('id')->paginate(50);

        return response()->json([
            'quotations' => $page->items(),
            'pagination' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
            'summary' => [
                'count' => $all->count(),
                'open_value' => round($open->sum('taxable_total'), 2),
                'open_count' => $open->count(),
                'won_value' => round($won->sum('taxable_total'), 2),
                'win_rate' => $decided > 0 ? round($won->count() / $decided * 100, 1) : null,
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['quotation' => Quotation::with(['items', 'product', 'client', 'invoice:id,number,status'])->findOrFail($id)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['quotation' => $this->service->save(null, $this->validated($request))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['quotation' => $this->service->save(Quotation::findOrFail($id), $this->validated($request))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $quote = Quotation::findOrFail($id);
        if ($quote->status === 'converted') {
            return response()->json(['error' => 'Converted quotations are kept with their invoice.'], 422);
        }
        $quote->delete();

        return response()->json(['success' => true]);
    }

    /** sent / accepted / declined (draft to reopen). */
    public function status(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'declined'])]]);
        $quote = Quotation::findOrFail($id);
        if ($quote->status === 'converted') {
            return response()->json(['error' => 'This quotation is already converted to an invoice.'], 422);
        }
        $quote->update([
            'status' => $data['status'],
            'sent_at' => $data['status'] === 'sent' ? ($quote->sent_at ?? now()) : $quote->sent_at,
            'decided_at' => in_array($data['status'], ['accepted', 'declined'], true) ? now() : null,
        ]);

        return $this->show($id);
    }

    public function convert(int $id): JsonResponse
    {
        $invoice = $this->service->convert(Quotation::with('items')->findOrFail($id));

        return response()->json(['invoice' => $invoice], 201);
    }

    public function duplicate(int $id): JsonResponse
    {
        return response()->json(['quotation' => $this->service->duplicate(Quotation::findOrFail($id))], 201);
    }

    public function email(Request $request, int $id, EmailService $mailer): JsonResponse
    {
        $quote = Quotation::with(['items', 'product', 'client'])->findOrFail($id);
        $data = $request->validate(['to' => 'required|email', 'subject' => 'required|string|max:255', 'message' => 'required|string|max:5000']);

        $result = $mailer->send($data['to'], $data['subject'], nl2br(e($data['message'])), null,
            [['filename' => QuotationPdf::filename($quote), 'content' => base64_encode(QuotationPdf::render($quote))]]);
        if (! $result['success']) {
            return response()->json(['error' => $result['error'] ?? 'Failed to send email'], 502);
        }
        if ($quote->status === 'draft') {
            $quote->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return response()->json(['success' => true, 'stub' => ! config('lalogix.resend_api_key')]);
    }

    /** Web route (session auth). */
    public function pdf(Request $request, int $id): Response
    {
        $quote = Quotation::findOrFail($id);

        return response(QuotationPdf::render($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.QuotationPdf::filename($quote).'"',
        ]);
    }

    /** Same payload as the invoice builder; due_date is "valid until". */
    private function validated(Request $request): array
    {
        return $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'client_id' => 'required|integer|exists:clients,id',
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'place_of_supply' => ['nullable', Rule::in([...array_keys(GstStates::ALL), GstStates::OUTSIDE_INDIA])],
            'export_with_lut' => 'boolean',
            'notes' => 'nullable|string|max:2000',
            'terms' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:1000',
            'items.*.sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'items.*.quantity' => 'required|numeric|gt:0|max:9999999',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.rate' => 'required|numeric|min:0|max:9999999999',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.gst_rate' => ['required', 'numeric', Rule::in(SettingsController::GST_RATES)],
        ], [
            'items.required' => 'Add at least one line.',
            'items.*.description.required' => 'Each line needs a description.',
            'due_date.after_or_equal' => 'Valid-until must be on or after the quotation date.',
        ]);
    }
}
