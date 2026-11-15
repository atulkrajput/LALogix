<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quotation;

/** Quotation PDF, rendered with the invoice template in its quotation mode. */
class QuotationPdf
{
    public static function render(Quotation $quote): string
    {
        $quote->loadMissing(['items', 'product', 'client']);

        // An unsaved Invoice carrying the quotation's figures, so the template stays the same.
        $doc = new Invoice([
            'type' => Invoice::TYPE_INVOICE,
            'status' => 'issued',
            'number' => $quote->number,
            'invoice_date' => $quote->quote_date,
            'due_date' => $quote->valid_until,
            'place_of_supply' => $quote->place_of_supply,
            'supply_type' => $quote->supply_type,
            'export_with_lut' => $quote->export_with_lut,
            'reverse_charge' => false,
            ...$quote->only(['bill_name', 'bill_company', 'bill_gstin', 'bill_address', 'bill_state_code', 'bill_country', 'bill_email',
                'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'round_off', 'grand_total', 'notes', 'terms']),
            'amount_paid' => 0,
            'amount_credited' => 0,
        ]);
        $doc->setRelation('items', $quote->items->map(fn ($i) => new InvoiceItem($i->only([
            'description', 'sac', 'quantity', 'unit', 'rate', 'discount', 'taxable_value', 'gst_rate', 'cgst', 'sgst', 'igst', 'amount',
        ]))));
        $doc->setRelation('product', $quote->product);
        $doc->setRelation('client', $quote->client);
        $doc->setRelation('originalInvoice', null);

        return InvoicePdf::render($doc, true);
    }

    public static function filename(Quotation $quote): string
    {
        return str_replace('/', '-', $quote->number).'.pdf';
    }
}
