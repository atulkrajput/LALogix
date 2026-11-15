<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceSequence;
use App\Models\Payment;
use App\Models\Product;
use App\Models\WorkItem;
use App\Support\GstStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoice / credit-note lifecycle:
 *   draft (editable, no number) → issued (numbered, frozen) → cancelled
 * Payments and credit notes reduce an issued invoice's balance.
 */
class InvoiceService
{
    /**
     * Create or update a draft from the builder payload.
     *
     * @param  array<string, mixed>  $data  validated: product_id, client_id, invoice_date, due_date?, place_of_supply?,
     *                                      export_with_lut?, reverse_charge?, notes?, terms?, reason?, items[]
     */
    public function saveDraft(?Invoice $invoice, array $data, string $type = Invoice::TYPE_INVOICE): Invoice
    {
        if ($invoice && ! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'Only draft documents can be edited.']);
        }

        $profile = BusinessProfile::current();
        $this->requireSellerState($profile);

        $client = Client::findOrFail($data['client_id']);
        $product = Product::findOrFail($data['product_id']);

        return DB::transaction(function () use ($invoice, $data, $type, $profile, $client, $product) {
            $invoice ??= new Invoice(['type' => $type]);
            $original = $invoice->original_invoice_id ? Invoice::find($invoice->original_invoice_id) : null;

            if ($original) {
                // A credit note always follows its invoice's buyer and place of supply.
                $pos = $original->place_of_supply;
                $exportWithLut = $original->export_with_lut;
            } else {
                $pos = $data['place_of_supply'] ?? null ?: $this->defaultPlaceOfSupply($client);
                $exportWithLut = $pos === GstStates::OUTSIDE_INDIA && (bool) ($data['export_with_lut'] ?? (bool) $profile->lut_number);
            }

            $supplyType = InvoiceCalculator::supplyType($profile->state_code, $pos);
            $date = Carbon::parse($data['invoice_date']);

            $invoice->fill([
                'product_id' => $product->id,
                'client_id' => $client->id,
                'invoice_date' => $date->toDateString(),
                'due_date' => $invoice->isCreditNote() ? null
                    : ($data['due_date'] ?? null ?: $date->copy()->addDays($profile->payment_terms_days)->toDateString()),
                'place_of_supply' => $pos,
                'supply_type' => $supplyType,
                'export_with_lut' => $exportWithLut,
                'reverse_charge' => (bool) ($data['reverse_charge'] ?? false),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'reason' => $data['reason'] ?? null,
                ...$this->buyerSnapshot($client),
            ]);

            $calc = InvoiceCalculator::calculate($data['items'], $supplyType, $exportWithLut, $profile->round_off_total);
            $invoice->fill($calc['totals']);
            $invoice->save();

            // Replace the lines and re-link the work items they came from.
            $previousWork = $invoice->items()->whereNotNull('work_item_id')->pluck('work_item_id')->all();
            InvoiceItem::where('invoice_id', $invoice->id)->delete();
            $linked = [];
            foreach ($data['items'] as $i => $line) {
                $invoice->items()->create([
                    'work_item_id' => $line['work_item_id'] ?? null,
                    'description' => $line['description'],
                    'sac' => $line['sac'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit' => $line['unit'] ?? 'NOS',
                    'rate' => $line['rate'],
                    'gst_rate' => $line['gst_rate'],
                    'sort' => $i,
                    ...$calc['lines'][$i],
                ]);
                if (! empty($line['work_item_id']) && ! $invoice->isCreditNote()) {
                    $linked[] = (int) $line['work_item_id'];
                }
            }
            if (! $invoice->isCreditNote()) {
                WorkItem::whereIn('id', array_diff($previousWork, $linked))->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
                WorkItem::whereIn('id', $linked)->where('client_id', $client->id)
                    ->where(fn ($q) => $q->whereNull('invoice_id')->orWhere('invoice_id', $invoice->id))
                    ->update(['invoice_id' => $invoice->id]);
            }

            return $invoice->fresh(['items', 'product', 'client']);
        });
    }

    /** Assign the next number in the series and freeze the document. */
    public function issue(Invoice $invoice): Invoice
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'This document is already issued.']);
        }
        if ($invoice->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Add at least one line before issuing.']);
        }

        $profile = BusinessProfile::current();
        $this->requireSellerState($profile);
        if (! $profile->gstin) {
            throw ValidationException::withMessages(['profile' => 'Add your GSTIN in Billing → Business profile before issuing.']);
        }

        $product = $invoice->product;
        $series = $invoice->isCreditNote() ? $product->credit_note_prefix : $product->invoice_prefix;
        if (! $series) {
            $field = $invoice->isCreditNote() ? 'credit note prefix' : 'invoice prefix';
            throw ValidationException::withMessages(['product' => "Set the {$field} for {$product->name} in Billing → Products first."]);
        }

        return DB::transaction(function () use ($invoice, $profile, $series) {
            if ($invoice->isCreditNote()) {
                $original = Invoice::lockForUpdate()->findOrFail($invoice->original_invoice_id);
                if ($original->status !== 'issued') {
                    throw ValidationException::withMessages(['invoice' => 'The original invoice is not issued.']);
                }
                $remaining = round($original->grand_total - $original->amount_credited, 2);
                if ($invoice->grand_total > $remaining + 0.001) {
                    throw ValidationException::withMessages(['items' => "Credit note total can't exceed ₹".number_format($remaining, 2).' still open on '.$original->number.'.']);
                }
            }

            $fy = InvoiceCalculator::financialYear($invoice->invoice_date);
            InvoiceSequence::firstOrCreate(['series' => $series, 'financial_year' => $fy], ['last_number' => 0]);
            $sequence = InvoiceSequence::where(['series' => $series, 'financial_year' => $fy])->lockForUpdate()->first();
            $next = $sequence->last_number + 1;
            $number = sprintf('%s/%s/%04d', $series, substr($fy, 2), $next);
            if (strlen($number) > 16) {
                throw ValidationException::withMessages(['product' => "Invoice number {$number} is longer than GST's 16-character limit; use a shorter prefix."]);
            }
            if (Invoice::where('number', $number)->exists()) {
                // e.g. the product's own system already sent this number through the ingest API.
                throw ValidationException::withMessages(['product' => "Number {$number} already exists (sent by the product's own system?). Use a different prefix for invoices issued here."]);
            }
            $sequence->update(['last_number' => $next]);

            $invoice->update([
                'number' => $number,
                'series' => $series,
                'financial_year' => $fy,
                'sequence' => $next,
                'status' => 'issued',
                'issued_at' => now(),
                'seller' => $this->sellerSnapshot($profile),
            ]);

            if ($invoice->isCreditNote()) {
                $this->refreshCredited($invoice->original_invoice_id);
            }

            return $invoice->fresh(['items', 'product', 'client']);
        });
    }

    /** Cancel an issued document; its number stays used (reported as cancelled in GSTR-1). */
    public function cancel(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Only issued documents can be cancelled.']);
        }
        if (! $invoice->isCreditNote()) {
            if ($invoice->payments()->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Delete the recorded payments before cancelling this invoice.']);
            }
            if ($invoice->creditNotes()->where('status', 'issued')->exists()) {
                throw ValidationException::withMessages(['invoice' => 'Cancel the credit notes issued against this invoice first.']);
            }
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            if ($invoice->isCreditNote()) {
                $this->refreshCredited($invoice->original_invoice_id);
            } else {
                WorkItem::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            }

            return $invoice->fresh(['items', 'product', 'client']);
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => 'Issued documents can be cancelled, not deleted.']);
        }
        DB::transaction(function () use ($invoice) {
            WorkItem::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
            $invoice->delete();
        });
    }

    /** New draft credit note pre-filled with the invoice's lines. */
    public function draftCreditNote(Invoice $invoice): Invoice
    {
        if ($invoice->isCreditNote() || $invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Credit notes can only be raised against issued invoices.']);
        }

        $note = new Invoice(['type' => Invoice::TYPE_CREDIT_NOTE, 'original_invoice_id' => $invoice->id]);

        return $this->saveDraft($note, [
            'product_id' => $invoice->product_id,
            'client_id' => $invoice->client_id,
            'invoice_date' => now()->toDateString(),
            'reason' => 'Correction in invoice value',
            'items' => $invoice->items->map(fn ($item) => [
                'description' => $item->description,
                'sac' => $item->sac,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
                'rate' => $item->rate,
                'discount' => $item->discount,
                'gst_rate' => $item->gst_rate,
            ])->all(),
        ], Invoice::TYPE_CREDIT_NOTE);
    }

    /** @param  array{payment_date: string, amount: float, tds_amount?: float, method?: string, reference?: string, notes?: string}  $data */
    public function addPayment(Invoice $invoice, array $data): Payment
    {
        if ($invoice->isCreditNote() || $invoice->status !== 'issued') {
            throw ValidationException::withMessages(['invoice' => 'Payments can only be recorded on issued invoices.']);
        }
        $total = round((float) $data['amount'] + (float) ($data['tds_amount'] ?? 0), 2);
        if ($total <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
        }
        if ($total > $invoice->balance_due + 0.001) {
            throw ValidationException::withMessages(['amount' => 'That is more than the ₹'.number_format($invoice->balance_due, 2).' balance due.']);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $payment = $invoice->payments()->create([
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'tds_amount' => $data['tds_amount'] ?? 0,
                'method' => $data['method'] ?? 'bank',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $this->refreshPaid($invoice);

            return $payment;
        });
    }

    public function deletePayment(Payment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $invoice = $payment->invoice;
            $payment->delete();
            $this->refreshPaid($invoice);
        });
    }

    private function refreshPaid(Invoice $invoice): void
    {
        $sum = $invoice->payments()->sum(DB::raw('amount + tds_amount'));
        $invoice->update(['amount_paid' => round((float) $sum, 2)]);
    }

    private function refreshCredited(int $invoiceId): void
    {
        $sum = Invoice::where('original_invoice_id', $invoiceId)->where('status', 'issued')->sum('grand_total');
        Invoice::whereKey($invoiceId)->update(['amount_credited' => round((float) $sum, 2)]);
    }

    public function requireSellerState(BusinessProfile $profile): void
    {
        if (! GstStates::valid($profile->state_code)) {
            throw ValidationException::withMessages(['profile' => 'Set your GST state in Billing → Business profile first (it decides CGST+SGST vs IGST).']);
        }
    }

    public function defaultPlaceOfSupply(Client $client): string
    {
        if (! $client->isIndian()) {
            return GstStates::OUTSIDE_INDIA;
        }
        if (! GstStates::valid($client->state_code)) {
            throw ValidationException::withMessages(['client_id' => "Set {$client->name}'s state (needed for the place of supply)."]);
        }

        return $client->state_code;
    }

    /** @return array<string, mixed> */
    public function buyerSnapshot(Client $client): array
    {
        return [
            'bill_name' => $client->company ?: $client->name,
            'bill_company' => $client->company ? $client->name : null, // contact person when billed to a company
            'bill_gstin' => $client->gstin,
            'bill_address' => $client->addressText(),
            'bill_state_code' => $client->isIndian() ? $client->state_code : GstStates::OUTSIDE_INDIA,
            'bill_country' => $client->country ?: 'India',
            'bill_email' => $client->email,
        ];
    }

    /** @return array<string, mixed> */
    private function sellerSnapshot(BusinessProfile $p): array
    {
        return [
            'legal_name' => $p->legal_name,
            'trade_name' => $p->trade_name,
            'gstin' => $p->gstin,
            'pan' => $p->pan,
            'state_code' => $p->state_code,
            'address' => $p->addressText(),
            'email' => $p->email,
            'phone' => $p->phone,
            'website' => $p->website,
            'bank_name' => $p->bank_name,
            'bank_account_name' => $p->bank_account_name,
            'bank_account_number' => $p->bank_account_number,
            'bank_ifsc' => $p->bank_ifsc,
            'bank_branch' => $p->bank_branch,
            'upi_id' => $p->upi_id,
            'lut_number' => $p->lut_number,
        ];
    }
}
