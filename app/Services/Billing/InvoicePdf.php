<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Support\AmountInWords;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoicePdf
{
    public static function render(Invoice $invoice, bool $isQuote = false): string
    {
        $invoice->loadMissing(['items', 'product', 'client', 'originalInvoice']);
        $profile = BusinessProfile::current();

        // Issued documents print the seller details frozen at issue time; drafts use the current profile.
        $seller = $invoice->seller ?: [
            'legal_name' => $profile->legal_name, 'trade_name' => $profile->trade_name, 'gstin' => $profile->gstin,
            'pan' => $profile->pan, 'state_code' => $profile->state_code, 'address' => $profile->addressText(),
            'email' => $profile->email, 'phone' => $profile->phone, 'website' => $profile->website,
            'bank_name' => $profile->bank_name, 'bank_account_name' => $profile->bank_account_name,
            'bank_account_number' => $profile->bank_account_number, 'bank_ifsc' => $profile->bank_ifsc,
            'bank_branch' => $profile->bank_branch, 'upi_id' => $profile->upi_id, 'lut_number' => $profile->lut_number,
        ];

        // Tax summary by SAC/HSN and rate, as printed under the lines.
        $taxSummary = $invoice->items->groupBy(fn ($i) => ($i->sac ?: '-').'|'.$i->gst_rate)->map(fn ($rows) => [
            'sac' => $rows->first()->sac ?: '-',
            'rate' => $rows->first()->gst_rate,
            'taxable' => $rows->sum('taxable_value'),
            'cgst' => $rows->sum('cgst'),
            'sgst' => $rows->sum('sgst'),
            'igst' => $rows->sum('igst'),
        ])->values();

        return Pdf::setOption(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false])->loadView('billing.invoice-pdf', [
            'invoice' => $invoice,
            'isQuote' => $isQuote,
            'seller' => $seller,
            'logo' => $profile->logo,
            'signature' => $profile->signature,
            'taxSummary' => $taxSummary,
            'amountInWords' => AmountInWords::rupees($invoice->grand_total),
        ])->setPaper('a4')->output();
    }

    public static function filename(Invoice $invoice): string
    {
        $name = $invoice->number ?: ('DRAFT-'.$invoice->id);

        return str_replace('/', '-', $name).'.pdf';
    }
}
