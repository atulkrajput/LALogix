@php
    use App\Support\GstStates;
    $intra = $invoice->supply_type === 'intra';
    $isCn = $invoice->isCreditNote();
    $money = fn ($v) => number_format((float) $v, 2);
    $taxCols = $intra ? 2 : 1;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $invoice->documentTitle() }} {{ $invoice->number ?? 'Draft' }}</title>
<style>
    @page { margin: 26px 28px 34px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9px; color: #1f2933; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .muted { color: #6b7280; }
    .right { text-align: right; }
    .center { text-align: center; }
    .bold { font-weight: bold; }
    .title { font-size: 16px; font-weight: bold; letter-spacing: 1px; color: #92400e; }
    .seller-name { font-size: 14px; font-weight: bold; }
    .box { border: 1px solid #d1d5db; padding: 7px 8px; }
    .label { font-size: 7.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .4px; }
    .items th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 5px 4px; font-size: 8px; }
    .items td { border: 1px solid #e5e7eb; padding: 5px 4px; }
    .totals td { padding: 3px 6px; }
    .grand td { border-top: 1.5px solid #1f2933; font-size: 11px; font-weight: bold; padding-top: 5px; }
    .summary th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 3px; font-size: 7.5px; }
    .summary td { border: 1px solid #e5e7eb; padding: 3px; font-size: 8px; }
    .stamp { position: fixed; top: 300px; left: 60px; font-size: 90px; font-weight: bold; color: rgba(220, 38, 38, .12); transform: rotate(-28deg); }
    .footer { position: fixed; bottom: -18px; left: 0; right: 0; text-align: center; font-size: 7.5px; color: #9ca3af; }
</style>
</head>
<body>
@if ($invoice->status === 'draft')<div class="stamp">DRAFT</div>@endif
@if ($invoice->status === 'cancelled')<div class="stamp">CANCELLED</div>@endif

<table>
    <tr>
        <td style="width: 58%;">
            @if ($logo)<img src="{{ $logo }}" style="max-height: 46px; max-width: 170px; margin-bottom: 6px;"><br>@endif
            <div class="seller-name">{{ $seller['trade_name'] ?: $seller['legal_name'] }}</div>
            @if (($seller['trade_name'] ?? null) && $seller['trade_name'] !== $seller['legal_name'])<div class="muted">{{ $seller['legal_name'] }}</div>@endif
            @if ($seller['address'] ?? null)<div>{{ $seller['address'] }}</div>@endif
            <div>
                @if ($seller['gstin'] ?? null)<span class="bold">GSTIN:</span> {{ $seller['gstin'] }} &nbsp;@endif
                @if ($seller['pan'] ?? null)<span class="bold">PAN:</span> {{ $seller['pan'] }}@endif
            </div>
            @if ($seller['state_code'] ?? null)<div><span class="bold">State:</span> {{ GstStates::label($seller['state_code']) }}</div>@endif
            <div class="muted">{{ implode(' · ', array_filter([$seller['email'] ?? null, $seller['phone'] ?? null, $seller['website'] ?? null])) }}</div>
        </td>
        <td class="right">
            <div class="title">{{ strtoupper($invoice->documentTitle()) }}</div>
            <div class="muted" style="margin-bottom: 8px;">{{ $isCn ? 'Original' : 'Original for Recipient' }}</div>
            <table style="width: auto; margin-left: auto;">
                <tr><td class="muted right" style="padding: 1px 6px;">{{ $isCn ? 'Credit Note No.' : 'Invoice No.' }}</td><td class="bold">{{ $invoice->number ?? 'DRAFT' }}</td></tr>
                <tr><td class="muted right" style="padding: 1px 6px;">Date</td><td>{{ $invoice->invoice_date->format('d M Y') }}</td></tr>
                @if (! $isCn && $invoice->due_date)
                    <tr><td class="muted right" style="padding: 1px 6px;">Due Date</td><td>{{ $invoice->due_date->format('d M Y') }}</td></tr>
                @endif
                @if ($isCn && $invoice->originalInvoice)
                    <tr><td class="muted right" style="padding: 1px 6px;">Against Invoice</td><td>{{ $invoice->originalInvoice->number }} ({{ $invoice->originalInvoice->invoice_date->format('d M Y') }})</td></tr>
                @endif
                <tr><td class="muted right" style="padding: 1px 6px;">Place of Supply</td><td>{{ GstStates::label($invoice->place_of_supply) }}</td></tr>
                <tr><td class="muted right" style="padding: 1px 6px;">Reverse Charge</td><td>{{ $invoice->reverse_charge ? 'Yes' : 'No' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<table style="margin-top: 12px;">
    <tr>
        <td class="box" style="width: 60%;">
            <div class="label">{{ $isCn ? 'Issued to' : 'Bill to' }}</div>
            <div class="bold" style="font-size: 10.5px;">{{ $invoice->bill_name }}</div>
            @if ($invoice->bill_company)<div>Attn: {{ $invoice->bill_company }}</div>@endif
            @if ($invoice->bill_address)<div>{!! nl2br(e($invoice->bill_address)) !!}</div>@endif
            @if ($invoice->bill_gstin)<div><span class="bold">GSTIN:</span> {{ $invoice->bill_gstin }}</div>@endif
            @if ($invoice->bill_state_code && $invoice->bill_state_code !== GstStates::OUTSIDE_INDIA)
                <div><span class="bold">State:</span> {{ GstStates::label($invoice->bill_state_code) }}</div>
            @endif
        </td>
        <td style="width: 2%;"></td>
        <td class="box">
            <div class="label">Supply</div>
            <div>{{ ['intra' => 'Intra-state (CGST + SGST)', 'inter' => 'Inter-state (IGST)', 'export' => 'Export of services'][$invoice->supply_type] ?? '' }}</div>
            @if ($invoice->product)<div class="muted" style="margin-top: 4px;">{{ $invoice->product->name }}</div>@endif
            @if ($isCn && $invoice->reason)<div style="margin-top: 4px;"><span class="bold">Reason:</span> {{ $invoice->reason }}</div>@endif
        </td>
    </tr>
</table>

<table class="items" style="margin-top: 12px;">
    <thead>
        <tr>
            <th style="width: 3%;">#</th>
            <th style="text-align: left;">Description</th>
            <th>SAC/HSN</th>
            <th>Qty</th>
            <th class="right">Rate</th>
            <th class="right">Discount</th>
            <th class="right">Taxable</th>
            @if ($intra)
                <th class="right">CGST</th><th class="right">SGST</th>
            @else
                <th class="right">IGST</th>
            @endif
            <th class="right">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $i => $item)
            <tr>
                <td class="center">{{ $i + 1 }}</td>
                <td>{!! nl2br(e($item->description)) !!}</td>
                <td class="center">{{ $item->sac }}</td>
                <td class="center">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}</td>
                <td class="right">{{ $money($item->rate) }}</td>
                <td class="right">{{ $item->discount > 0 ? $money($item->discount) : '-' }}</td>
                <td class="right">{{ $money($item->taxable_value) }}</td>
                @if ($intra)
                    <td class="right">{{ $money($item->cgst) }}<br><span class="muted">{{ $item->gst_rate / 2 }}%</span></td>
                    <td class="right">{{ $money($item->sgst) }}<br><span class="muted">{{ $item->gst_rate / 2 }}%</span></td>
                @else
                    <td class="right">{{ $money($item->igst) }}<br><span class="muted">{{ $invoice->supply_type === 'export' && $invoice->export_with_lut ? '0' : $item->gst_rate }}%</span></td>
                @endif
                <td class="right bold">{{ $money($item->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table style="margin-top: 10px;">
    <tr>
        <td style="width: 56%; padding-right: 14px;">
            <div class="label">Amount in words</div>
            <div class="bold" style="margin-bottom: 8px;">{{ $amountInWords }}</div>

            <table class="summary">
                <tr>
                    <th>SAC/HSN</th><th>Taxable</th>
                    @if ($intra)<th>CGST</th><th>SGST</th>@else<th>IGST</th>@endif
                    <th>Total tax</th>
                </tr>
                @foreach ($taxSummary as $row)
                    <tr>
                        <td class="center">{{ $row['sac'] }} @ {{ $row['rate'] }}%</td>
                        <td class="right">{{ $money($row['taxable']) }}</td>
                        @if ($intra)
                            <td class="right">{{ $money($row['cgst']) }}</td><td class="right">{{ $money($row['sgst']) }}</td>
                        @else
                            <td class="right">{{ $money($row['igst']) }}</td>
                        @endif
                        <td class="right">{{ $money($row['cgst'] + $row['sgst'] + $row['igst']) }}</td>
                    </tr>
                @endforeach
            </table>

            @if ($invoice->supply_type === 'export')
                <div style="margin-top: 8px;" class="bold">
                    @if ($invoice->export_with_lut)
                        Supply meant for export under LUT{{ ($seller['lut_number'] ?? null) ? ' No. '.$seller['lut_number'] : '' }} without payment of IGST.
                    @else
                        Supply meant for export with payment of IGST.
                    @endif
                </div>
            @endif
        </td>
        <td>
            <table class="totals">
                <tr><td class="muted">Taxable value</td><td class="right">{{ $money($invoice->taxable_total) }}</td></tr>
                @if ($intra)
                    <tr><td class="muted">CGST</td><td class="right">{{ $money($invoice->cgst_total) }}</td></tr>
                    <tr><td class="muted">SGST</td><td class="right">{{ $money($invoice->sgst_total) }}</td></tr>
                @else
                    <tr><td class="muted">IGST</td><td class="right">{{ $money($invoice->igst_total) }}</td></tr>
                @endif
                @if ((float) $invoice->round_off !== 0.0)
                    <tr><td class="muted">Round off</td><td class="right">{{ $money($invoice->round_off) }}</td></tr>
                @endif
                <tr class="grand"><td>Total</td><td class="right">₹ {{ $money($invoice->grand_total) }}</td></tr>
                @if (! $isCn && $invoice->status === 'issued' && ($invoice->amount_paid > 0 || $invoice->amount_credited > 0))
                    @if ($invoice->amount_credited > 0)<tr><td class="muted">Credit notes</td><td class="right">- {{ $money($invoice->amount_credited) }}</td></tr>@endif
                    @if ($invoice->amount_paid > 0)<tr><td class="muted">Received</td><td class="right">- {{ $money($invoice->amount_paid) }}</td></tr>@endif
                    <tr><td class="bold">Balance due</td><td class="right bold">₹ {{ $money($invoice->balance_due) }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<table style="margin-top: 14px;">
    <tr>
        <td style="width: 56%; padding-right: 14px;">
            @if (! $isCn && (($seller['bank_account_number'] ?? null) || ($seller['upi_id'] ?? null)))
                <div class="label">Payment details</div>
                @if ($seller['bank_account_number'] ?? null)
                    <div>{{ $seller['bank_account_name'] ?: $seller['legal_name'] }}</div>
                    <div>A/c No: <span class="bold">{{ $seller['bank_account_number'] }}</span> · IFSC: <span class="bold">{{ $seller['bank_ifsc'] }}</span></div>
                    <div>{{ implode(', ', array_filter([$seller['bank_name'] ?? null, $seller['bank_branch'] ?? null])) }}</div>
                @endif
                @if ($seller['upi_id'] ?? null)<div>UPI: <span class="bold">{{ $seller['upi_id'] }}</span></div>@endif
            @endif
            @if ($invoice->notes)
                <div class="label" style="margin-top: 8px;">Notes</div>
                <div>{!! nl2br(e($invoice->notes)) !!}</div>
            @endif
            @if ($invoice->terms)
                <div class="label" style="margin-top: 8px;">Terms</div>
                <div class="muted">{!! nl2br(e($invoice->terms)) !!}</div>
            @endif
        </td>
        <td class="right">
            <div>For <span class="bold">{{ $seller['trade_name'] ?: $seller['legal_name'] }}</span></div>
            @if ($signature)
                <img src="{{ $signature }}" style="max-height: 50px; max-width: 160px; margin: 6px 0;">
            @else
                <div style="height: 46px;"></div>
            @endif
            <div class="muted">Authorised Signatory</div>
        </td>
    </tr>
</table>

<div class="footer">This is a computer-generated {{ strtolower($invoice->documentTitle()) }}.</div>
</body>
</html>
