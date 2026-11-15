<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\GstStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quotations: numbered when created (QT/26-27/0001, its own series; not a GST document,
 * so gaps are fine), editable until converted, and converted into a draft invoice in one step.
 */
class QuotationService
{
    public function __construct(private InvoiceService $invoices) {}

    /** @param  array<string, mixed>  $data  same shape as the invoice builder (due_date = valid until) */
    public function save(?Quotation $quote, array $data): Quotation
    {
        if ($quote && $quote->status === 'converted') {
            throw ValidationException::withMessages(['quotation' => 'This quotation was converted to an invoice and can no longer be edited.']);
        }
        $profile = BusinessProfile::current();
        $this->invoices->requireSellerState($profile);
        $client = Client::findOrFail($data['client_id']);
        $product = Product::findOrFail($data['product_id']);

        $pos = ($data['place_of_supply'] ?? null) ?: $this->invoices->defaultPlaceOfSupply($client);
        $exportWithLut = $pos === GstStates::OUTSIDE_INDIA && (bool) ($data['export_with_lut'] ?? (bool) $profile->lut_number);
        $supplyType = InvoiceCalculator::supplyType($profile->state_code, $pos);
        $date = Carbon::parse($data['invoice_date']);
        $calc = InvoiceCalculator::calculate($data['items'], $supplyType, $exportWithLut, $profile->round_off_total);

        return DB::transaction(function () use ($quote, $data, $profile, $client, $product, $pos, $exportWithLut, $supplyType, $date, $calc) {
            $quote ??= new Quotation(['number' => $this->nextNumber($profile, $date), 'status' => 'draft']);
            $quote->fill([
                'product_id' => $product->id,
                'client_id' => $client->id,
                'quote_date' => $date->toDateString(),
                'valid_until' => ($data['due_date'] ?? null) ?: $date->copy()->addDays($profile->quotation_valid_days ?: 15)->toDateString(),
                'place_of_supply' => $pos,
                'supply_type' => $supplyType,
                'export_with_lut' => $exportWithLut,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                ...$this->invoices->buyerSnapshot($client),
                ...$calc['totals'],
            ]);
            $quote->save();

            QuotationItem::where('quotation_id', $quote->id)->delete();
            foreach ($data['items'] as $i => $line) {
                QuotationItem::create([
                    'quotation_id' => $quote->id,
                    'description' => $line['description'],
                    'sac' => $line['sac'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? 'NOS',
                    'rate' => $line['rate'],
                    'gst_rate' => $line['gst_rate'],
                    'sort' => $i,
                    ...$calc['lines'][$i],
                ]);
            }

            return $quote->fresh(['items', 'product', 'client']);
        });
    }

    /** Draft invoice with the quotation's lines; the quotation is marked converted. */
    public function convert(Quotation $quote): Invoice
    {
        if ($quote->status === 'converted' && $quote->invoice_id) {
            throw ValidationException::withMessages(['quotation' => 'This quotation is already converted.']);
        }
        if ($quote->status === 'declined') {
            throw ValidationException::withMessages(['quotation' => 'This quotation was declined. Mark it accepted first if the client changed their mind.']);
        }
        $quote->loadMissing('items');

        return DB::transaction(function () use ($quote) {
            $invoice = $this->invoices->saveDraft(null, [
                'product_id' => $quote->product_id,
                'client_id' => $quote->client_id,
                'invoice_date' => now()->toDateString(),
                'place_of_supply' => $quote->place_of_supply,
                'export_with_lut' => $quote->export_with_lut,
                'notes' => trim(($quote->notes ? $quote->notes."\n" : '')."As per quotation {$quote->number}."),
                'terms' => $quote->terms,
                'items' => $quote->items->map(fn ($i) => [
                    'description' => $i->description, 'sac' => $i->sac, 'quantity' => $i->quantity, 'unit' => $i->unit,
                    'rate' => $i->rate, 'discount' => $i->discount, 'gst_rate' => $i->gst_rate,
                ])->all(),
            ]);
            $quote->update(['status' => 'converted', 'invoice_id' => $invoice->id, 'decided_at' => $quote->decided_at ?? now()]);

            return $invoice;
        });
    }

    public function duplicate(Quotation $quote): Quotation
    {
        $quote->loadMissing('items');

        return $this->save(null, [
            'product_id' => $quote->product_id,
            'client_id' => $quote->client_id,
            'invoice_date' => now()->toDateString(),
            'place_of_supply' => $quote->place_of_supply,
            'export_with_lut' => $quote->export_with_lut,
            'notes' => $quote->notes,
            'terms' => $quote->terms,
            'items' => $quote->items->map(fn ($i) => [
                'description' => $i->description, 'sac' => $i->sac, 'quantity' => $i->quantity, 'unit' => $i->unit,
                'rate' => $i->rate, 'discount' => $i->discount, 'gst_rate' => $i->gst_rate,
            ])->all(),
        ]);
    }

    private function nextNumber(BusinessProfile $profile, Carbon $date): string
    {
        $prefix = strtoupper($profile->quotation_prefix ?: 'QT');
        $fy = InvoiceCalculator::financialYear($date);
        $series = mb_substr('~'.$prefix, 0, 5); // own counter, never shared with an invoice series
        InvoiceSequence::firstOrCreate(['series' => $series, 'financial_year' => $fy], ['last_number' => 0]);
        $seq = InvoiceSequence::where(['series' => $series, 'financial_year' => $fy])->lockForUpdate()->first();
        do {
            $seq->last_number++;
            $number = sprintf('%s/%s/%04d', $prefix, substr($fy, 2), $seq->last_number);
        } while (Quotation::where('number', $number)->exists());
        $seq->save();

        return $number;
    }
}
