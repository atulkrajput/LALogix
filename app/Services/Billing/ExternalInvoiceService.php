<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\IngestLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Support\Gstin;
use App\Support\GstStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Records invoices that a product (VIVK.in, Amritix, …) already issued to its own
 * customers, so they appear in the consolidated GST reports.
 *
 * - The product's own number and date are kept; documents arrive as issued.
 * - GST is recalculated from the lines (to catch mistakes); when the product sends
 *   its total, the stored total matches it exactly (difference kept as round-off,
 *   at most ₹1).
 * - Idempotent by external_id: resending the same document is "unchanged";
 *   resending different amounts is rejected (use a credit note or cancel).
 */
class ExternalInvoiceService
{
    public const GST_RATES = [0, 0.1, 0.25, 1, 1.5, 3, 5, 6, 7.5, 12, 18, 28];

    public function __construct(private InvoiceService $invoices) {}

    /** @return array{outcome: string, invoice: Invoice} */
    public function recordInvoice(Product $product, array $payload, string $channel = 'api', ?string $ip = null): array
    {
        return $this->logged($product, $channel, 'invoice', $payload, $ip, function () use ($product, $payload, $channel) {
            $data = $this->validate($payload, false);

            return $this->store($product, $data, Invoice::TYPE_INVOICE, $channel);
        });
    }

    /** @return array{outcome: string, invoice: Invoice} */
    public function recordCreditNote(Product $product, array $payload, string $channel = 'api', ?string $ip = null): array
    {
        return $this->logged($product, $channel, 'credit_note', $payload, $ip, function () use ($product, $payload, $channel) {
            $data = $this->validate($payload, true);
            $original = Invoice::where('product_id', $product->id)->where('type', Invoice::TYPE_INVOICE)
                ->where('external_id', $data['invoice_external_id'])->first();
            if (! $original) {
                throw ValidationException::withMessages(['invoice_external_id' => "No invoice with external_id {$data['invoice_external_id']} has been sent for {$product->name}."]);
            }
            if ($original->status !== 'issued') {
                throw ValidationException::withMessages(['invoice_external_id' => "Invoice {$original->number} is cancelled."]);
            }

            return $this->store($product, $data, Invoice::TYPE_CREDIT_NOTE, $channel, $original);
        });
    }

    /** @return array{outcome: string, invoice: Invoice} */
    public function cancel(Product $product, string $type, string $externalId, ?string $reason, string $channel = 'api', ?string $ip = null): array
    {
        return $this->logged($product, $channel, 'cancel', ['external_id' => $externalId], $ip, function () use ($product, $type, $externalId, $reason) {
            $doc = Invoice::where('product_id', $product->id)->where('type', $type)->where('external_id', $externalId)->first();
            if (! $doc) {
                throw ValidationException::withMessages(['external_id' => "No document with external_id {$externalId} has been sent for {$product->name}."]);
            }
            if ($doc->status === 'cancelled') {
                return ['outcome' => 'unchanged', 'invoice' => $doc];
            }
            // Payments recorded by the product's sync are cleared with the cancellation.
            $doc->payments()->delete();
            $doc->update(['amount_paid' => 0]);

            return ['outcome' => 'cancelled', 'invoice' => $this->invoices->cancel($doc->fresh(), $reason ?: 'Cancelled in '.$product->name)];
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $payload, bool $creditNote): array
    {
        $rules = [
            'external_id' => 'required|string|max:100',
            'number' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'date' => 'required|date_format:Y-m-d',
            'reason' => [$creditNote ? 'required' : 'nullable', 'string', 'max:255'],
            'notes' => 'nullable|string|max:2000',
            'total' => 'nullable|numeric|min:0',
            'items' => 'required|array|min:1|max:100',
            'items.*.description' => 'required|string|max:1000',
            'items.*.sac' => ['nullable', 'regex:/^[0-9]{4,8}$/'],
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.unit' => 'nullable|string|max:10',
            'items.*.rate' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.gst_rate' => ['required', 'numeric', Rule::in(self::GST_RATES)],
        ];
        if ($creditNote) {
            $rules['invoice_external_id'] = 'required|string|max:100';
        } else {
            $rules += [
                'customer' => 'required|array',
                'customer.name' => 'required|string|max:255',
                'customer.company' => 'nullable|string|max:255',
                'customer.gstin' => ['nullable', 'string', function ($attr, $value, $fail) {
                    if ($value && ! Gstin::isValid($value)) {
                        $fail('customer.gstin is not a valid GSTIN.');
                    }
                }],
                'customer.email' => 'nullable|email|max:255',
                'customer.phone' => 'nullable|string|max:30',
                'customer.address_line1' => 'nullable|string|max:255',
                'customer.address_line2' => 'nullable|string|max:255',
                'customer.city' => 'nullable|string|max:100',
                'customer.state_code' => ['nullable', Rule::in(array_keys(GstStates::ALL))],
                'customer.pincode' => 'nullable|string|max:10',
                'customer.country' => 'nullable|string|max:100',
                'customer.utm_source' => 'nullable|string|max:191',
                'customer.utm_campaign' => 'nullable|string|max:191',
                'place_of_supply' => ['nullable', Rule::in([...array_keys(GstStates::ALL), GstStates::OUTSIDE_INDIA])],
                'reverse_charge' => 'nullable|boolean',
                'export_with_lut' => 'nullable|boolean',
                'payment' => 'nullable|array',
                'payment.date' => 'required_with:payment|date_format:Y-m-d',
                'payment.amount' => 'required_with:payment|numeric|gt:0',
                'payment.method' => ['nullable', Rule::in(['bank', 'upi', 'cash', 'cheque', 'card', 'other'])],
                'payment.reference' => 'nullable|string|max:255',
            ];
        }

        return Validator::make($payload, $rules, [
            'number.regex' => 'number may only contain letters, digits, "/" and "-" (GST rule).',
            'date.date_format' => 'date must be YYYY-MM-DD.',
        ])->validate();
    }

    /** @return array{outcome: string, invoice: Invoice} */
    private function store(Product $product, array $data, string $type, string $channel, ?Invoice $original = null): array
    {
        $profile = BusinessProfile::current();
        if (! GstStates::valid($profile->state_code)) {
            throw ValidationException::withMessages(['profile' => 'LALogix business profile has no GST state yet; set it in Billing → Business profile.']);
        }

        if ($original) {
            $pos = $original->place_of_supply;
            $exportWithLut = $original->export_with_lut;
            $client = $original->client;
            $buyer = $original->only(['bill_name', 'bill_company', 'bill_gstin', 'bill_address', 'bill_state_code', 'bill_country', 'bill_email']);
            $reverseCharge = $original->reverse_charge;
        } else {
            $customer = $data['customer'];
            $customer['gstin'] = Gstin::normalize($customer['gstin'] ?? null);
            $indian = strcasecmp(trim($customer['country'] ?? 'India') ?: 'India', 'India') === 0;
            $state = $customer['gstin'] ? Gstin::stateCode($customer['gstin']) : ($customer['state_code'] ?? null);
            // Indian customer with no state on record (e.g. a SaaS sign-up with only name + email):
            // for services the place of supply is then the supplier's location
            // (IGST Act s.12(2)(b)(ii)), i.e. an intra-state sale.
            $pos = $data['place_of_supply'] ?? null ?: ($indian ? ($state ?: $profile->state_code) : GstStates::OUTSIDE_INDIA);
            $exportWithLut = $pos === GstStates::OUTSIDE_INDIA && (bool) ($data['export_with_lut'] ?? (bool) $profile->lut_number);
            $client = $this->client($product, $customer, $indian, $state);
            $buyer = [
                'bill_name' => ($customer['company'] ?? null) ?: $customer['name'],
                'bill_company' => ($customer['company'] ?? null) ? $customer['name'] : null,
                'bill_gstin' => $customer['gstin'],
                'bill_address' => $client->addressText() ?: null,
                'bill_state_code' => $indian ? $state : GstStates::OUTSIDE_INDIA,
                'bill_country' => $indian ? 'India' : trim($customer['country']),
                'bill_email' => $customer['email'] ?? null,
            ];
            $reverseCharge = (bool) ($data['reverse_charge'] ?? false);
        }

        $supplyType = InvoiceCalculator::supplyType($profile->state_code, $pos);
        $calc = InvoiceCalculator::calculate($data['items'], $supplyType, $exportWithLut, false);
        $totals = $calc['totals'];
        if (isset($data['total'])) {
            $diff = round((float) $data['total'] - $totals['grand_total'], 2);
            if (abs($diff) > 1) {
                throw ValidationException::withMessages(['total' => sprintf(
                    'total %.2f does not match the lines (taxable %.2f + GST %.2f = %.2f for %s). Check gst_rate and place_of_supply.',
                    $data['total'], $totals['taxable_total'], $totals['grand_total'] - $totals['taxable_total'], $totals['grand_total'],
                    ['intra' => 'an intra-state supply', 'inter' => 'an inter-state supply', 'export' => 'an export'][$supplyType],
                )]);
            }
            $totals['round_off'] = $diff;
            $totals['grand_total'] = round((float) $data['total'], 2);
        }

        $existing = Invoice::where('product_id', $product->id)->where('type', $type)->where('external_id', $data['external_id'])->first();
        if ($existing) {
            $same = $existing->number === $data['number']
                && $existing->invoice_date->toDateString() === $data['date']
                && abs($existing->grand_total - $totals['grand_total']) < 0.005
                && abs($existing->taxable_total - $totals['taxable_total']) < 0.005;
            if (! $same) {
                throw ValidationException::withMessages(['external_id' => "external_id {$data['external_id']} was already recorded as {$existing->number} with different details. Issued GST documents can't be changed: send a credit note or cancel it."]);
            }

            return ['outcome' => 'unchanged', 'invoice' => $existing];
        }
        if ($original) {
            $remaining = round($original->grand_total - $original->amount_credited, 2);
            if ($totals['grand_total'] > $remaining + 0.005) {
                throw ValidationException::withMessages(['total' => sprintf('Credit note total %.2f is more than the %.2f still open on %s.', $totals['grand_total'], $remaining, $original->number)]);
            }
        }
        if (Invoice::where('number', $data['number'])->exists()) {
            throw ValidationException::withMessages(['number' => "Number {$data['number']} is already used by another document. Each product needs its own series."]);
        }

        $date = Carbon::parse($data['date']);
        $invoice = DB::transaction(function () use ($product, $data, $type, $channel, $original, $client, $buyer, $pos, $supplyType, $exportWithLut, $reverseCharge, $calc, $totals, $date, $profile) {
            $invoice = Invoice::create([
                'type' => $type,
                'source' => $channel,
                'external_id' => $data['external_id'],
                'product_id' => $product->id,
                'client_id' => $client->id,
                'original_invoice_id' => $original?->id,
                'number' => $data['number'],
                'series' => $this->series($product, $data['number'], $type),
                'financial_year' => InvoiceCalculator::financialYear($date),
                'sequence' => preg_match('/(\d+)$/', $data['number'], $m) ? (int) $m[1] : null,
                'status' => 'issued',
                'invoice_date' => $date->toDateString(),
                'due_date' => null,
                'place_of_supply' => $pos,
                'supply_type' => $supplyType,
                'export_with_lut' => $exportWithLut,
                'reverse_charge' => $reverseCharge,
                ...$buyer,
                ...$totals,
                'notes' => $data['notes'] ?? null,
                'reason' => $data['reason'] ?? null,
                'issued_at' => now(),
                'seller' => ['legal_name' => $profile->legal_name, 'trade_name' => $profile->trade_name, 'gstin' => $profile->gstin, 'state_code' => $profile->state_code],
            ]);
            foreach ($data['items'] as $i => $line) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $line['description'],
                    'sac' => $line['sac'] ?? $product->default_sac,
                    'quantity' => $line['quantity'],
                    'unit' => strtoupper($line['unit'] ?? 'NOS') ?: 'NOS',
                    'rate' => $line['rate'],
                    'gst_rate' => $line['gst_rate'],
                    'sort' => $i,
                    ...$calc['lines'][$i],
                ]);
            }
            if ($original) {
                Invoice::whereKey($original->id)->update(['amount_credited' => round((float) Invoice::where('original_invoice_id', $original->id)->where('status', 'issued')->sum('grand_total'), 2)]);
            }

            return $invoice;
        });

        if (! empty($data['payment'])) {
            $p = $data['payment'];
            $this->invoices->addPayment($invoice->fresh(), [
                'payment_date' => $p['date'],
                'amount' => min((float) $p['amount'], $invoice->grand_total),
                'method' => $p['method'] ?? 'other',
                'reference' => $p['reference'] ?? null,
                'notes' => 'Recorded by '.$product->name,
            ]);
        }

        return ['outcome' => 'created', 'invoice' => $invoice->fresh()];
    }

    /** Find the customer by GSTIN, then email; otherwise create them. */
    private function client(Product $product, array $c, bool $indian, ?string $state): Client
    {
        $client = null;
        if ($c['gstin']) {
            $client = Client::where('gstin', $c['gstin'])->first();
        }
        if (! $client && ! empty($c['email'])) {
            $client = Client::whereRaw('LOWER(email) = ?', [strtolower($c['email'])])->first();
        }

        return $client ?? Client::create([
            'name' => $c['name'],
            'company' => $c['company'] ?? null,
            'gstin' => $c['gstin'],
            'pan' => $c['gstin'] ? Gstin::pan($c['gstin']) : null,
            'email' => $c['email'] ?? null,
            'phone' => $c['phone'] ?? null,
            'address_line1' => $c['address_line1'] ?? null,
            'address_line2' => $c['address_line2'] ?? null,
            'city' => $c['city'] ?? null,
            'state_code' => $indian ? $state : null,
            'pincode' => $c['pincode'] ?? null,
            'country' => $indian ? 'India' : trim($c['country']),
            'product_id' => $product->id,
            // Which campaign brought this customer to the product (for the Marketing report)
            'utm_source' => $c['utm_source'] ?? null,
            'utm_campaign' => $c['utm_campaign'] ?? null,
        ]);
    }

    /** Series for the documents table: the product's prefix if the number uses it, else the leading letters. */
    private function series(Product $product, string $number, string $type): string
    {
        $prefix = $type === Invoice::TYPE_CREDIT_NOTE ? $product->credit_note_prefix : $product->invoice_prefix;
        if ($prefix && str_starts_with(strtoupper($number), strtoupper($prefix))) {
            return $prefix;
        }
        preg_match('/^[A-Za-z]*/', $number, $m);

        return strtoupper(substr($m[0] ?: $product->slug, 0, 5));
    }

    /** Runs an operation and records the outcome in ingest_logs. */
    private function logged(Product $product, string $channel, string $action, array $payload, ?string $ip, callable $fn): array
    {
        try {
            $result = $fn();
            IngestLog::create([
                'product_id' => $product->id, 'channel' => $channel, 'action' => $action,
                'external_id' => mb_substr((string) ($payload['external_id'] ?? ''), 0, 100) ?: null,
                'number' => $result['invoice']->number, 'outcome' => $result['outcome'],
                'invoice_id' => $result['invoice']->id, 'ip' => $ip,
            ]);
            $product->forceFill(['last_synced_at' => now()])->saveQuietly();

            return $result;
        } catch (ValidationException $e) {
            IngestLog::create([
                'product_id' => $product->id, 'channel' => $channel, 'action' => $action,
                'external_id' => mb_substr((string) ($payload['external_id'] ?? ''), 0, 100) ?: null,
                'number' => mb_substr((string) ($payload['number'] ?? ''), 0, 16) ?: null,
                'outcome' => 'rejected', 'message' => mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 500), 'ip' => $ip,
            ]);
            throw $e;
        }
    }
}
