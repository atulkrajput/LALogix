<?php

namespace App\Services\Billing;

use App\Models\BusinessProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Purchase;
use App\Support\GstStates;
use App\Support\SimpleXlsx;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The accountant's pack for a period: Excel books (sales, credit notes, line items, receipts,
 * purchases, debtors), the GST report, Tally import files and, optionally, the invoice PDFs,
 * zipped together.
 *
 * Tally files follow the standard XML import envelope (Gateway of Tally → Import → Masters /
 * Transactions). Masters (ledgers) and vouchers are separate files so the masters can be imported
 * once and vouchers every period. Ledger amounts use Tally's sign convention: debit negative with
 * ISDEEMEDPOSITIVE=Yes, credit positive with ISDEEMEDPOSITIVE=No. Party entries carry bill-wise
 * references (New Ref on the invoice / bill, Agst Ref on receipts, payments and credit notes) so
 * outstanding balances match in Tally.
 */
class AccountingExport
{
    public const PARTS = ['books', 'gst', 'tally', 'pdfs'];
    public const MAX_PDFS = 300;

    private const BANK = 'Bank Account';
    private const CASH = 'Cash';
    private const TDS = 'TDS Receivable';
    private const ROUND_OFF = 'Round Off';
    private const OTHER_CREDITORS = 'Sundry Creditors - Others';
    private const HEADS = ['cgst' => 'Central Tax', 'sgst' => 'State Tax', 'igst' => 'Integrated Tax'];

    private Carbon $from;
    private Carbon $to;
    private Collection $docs;
    private Collection $payments;
    private Collection $purchases;
    private Collection $vendorPayments;

    /** @var array<string, array{parent: string, extra: array<string, string>}> */
    private array $ledgers = [];
    /** @var array<string, string> party key => ledger name */
    private array $partyNames = [];

    public function __construct(private GstReportService $reports) {}

    public function load(Carbon $from, Carbon $to): self
    {
        $this->from = $from->copy()->startOfDay();
        $this->to = $to->copy()->startOfDay();
        [$f, $t] = [$this->from->toDateString(), $this->to->toDateString()];

        $this->docs = Invoice::with(['items', 'product', 'client', 'originalInvoice'])
            ->whereIn('status', ['issued', 'cancelled'])->whereDate('invoice_date', '>=', $f)->whereDate('invoice_date', '<=', $t)
            ->orderBy('invoice_date')->orderBy('id')->get();
        $this->payments = Payment::with('invoice:id,number,type,client_id,bill_name,bill_company,bill_gstin,bill_state_code,bill_country')
            ->whereDate('payment_date', '>=', $f)->whereDate('payment_date', '<=', $t)->orderBy('payment_date')->orderBy('id')->get();
        $this->purchases = Purchase::with(['vendor:id,name,gstin,state_code,country', 'product:id,name'])
            ->whereDate('bill_date', '>=', $f)->whereDate('bill_date', '<=', $t)->orderBy('bill_date')->orderBy('id')->get();
        $this->vendorPayments = Purchase::with('vendor:id,name,gstin,state_code,country')->whereNotNull('vendor_id')->where('payment_status', 'paid')
            ->whereDate('paid_on', '>=', $f)->whereDate('paid_on', '<=', $t)->orderBy('paid_on')->orderBy('id')->get();

        return $this;
    }

    /** What the pack will contain (shown before downloading). */
    public function counts(): array
    {
        return [
            'invoices' => $this->docs->where('type', Invoice::TYPE_INVOICE)->where('status', 'issued')->count(),
            'credit_notes' => $this->docs->where('type', Invoice::TYPE_CREDIT_NOTE)->where('status', 'issued')->count(),
            'cancelled' => $this->docs->where('status', 'cancelled')->count(),
            'receipts' => $this->payments->count(),
            'purchases' => $this->purchases->count(),
            'vendor_payments' => $this->vendorPayments->count(),
            'pdfs' => $this->docs->count(),
            'max_pdfs' => self::MAX_PDFS,
        ];
    }

    /** @param list<string> $parts */
    public function zip(array $parts): string
    {
        $base = 'Books-'.$this->label();
        $files = [];
        if (in_array('books', $parts, true)) {
            $files["{$base}/Books-{$this->label()}.xlsx"] = $this->books();
        }
        if (in_array('gst', $parts, true)) {
            $files["{$base}/GST-report-{$this->label()}.xlsx"] = GstExport::full($this->reports->build($this->from, $this->to));
        }
        if (in_array('tally', $parts, true)) {
            [$masters, $vouchers] = $this->tally();
            $files["{$base}/Tally/1-Masters.xml"] = $masters;
            $files["{$base}/Tally/2-Vouchers.xml"] = $vouchers;
        }
        if (in_array('pdfs', $parts, true)) {
            foreach ($this->docs->take(self::MAX_PDFS) as $doc) {
                $folder = $doc->isCreditNote() ? 'Credit notes' : 'Invoices';
                $files["{$base}/{$folder}/".InvoicePdf::filename($doc)] = InvoicePdf::render($doc);
            }
        }
        $files["{$base}/README.txt"] = $this->readme($parts);

        return SimpleXlsx::zip($files);
    }

    public function filename(): string
    {
        return 'Books-'.$this->label().'.zip';
    }

    /** "2026-11" for a calendar month, "FY2026-27" for a financial year, else "2026-11-01_to_2026-12-15". */
    public function label(): string
    {
        if ($this->from->day === 1 && $this->to->isSameDay($this->from->copy()->endOfMonth())) {
            return $this->from->format('Y-m');
        }
        if ($this->from->format('m-d') === '04-01' && $this->to->format('m-d') === '03-31' && $this->to->year === $this->from->year + 1) {
            return 'FY'.$this->from->year.'-'.$this->to->format('y');
        }

        return $this->from->toDateString().'_to_'.$this->to->toDateString();
    }

    // ---- Excel books ----------------------------------------------------------------

    public function books(): string
    {
        $invoices = $this->docs->where('type', Invoice::TYPE_INVOICE);
        $notes = $this->docs->where('type', Invoice::TYPE_CREDIT_NOTE);
        $x = new SimpleXlsx();
        $x->addSheet('Summary', $this->summarySheet());

        $x->addSheet('Sales register', [
            ['Date', 'Invoice No.', 'Party', 'GSTIN', 'Place of supply', 'Business line', 'Supply', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Round off', 'Invoice total', 'Status', 'Received', 'Balance'],
            ...$invoices->map(fn (Invoice $i) => $i->status === 'cancelled'
                ? [$this->d($i->invoice_date), $i->number, $this->partyLabel($i), $i->bill_gstin, GstStates::label($i->place_of_supply), $i->product?->name, $this->supplyLabel($i), 0, 0, 0, 0, 0, 0, 'Cancelled'.($i->cancel_reason ? ": {$i->cancel_reason}" : ''), 0, 0]
                : [$this->d($i->invoice_date), $i->number, $this->partyLabel($i), $i->bill_gstin, GstStates::label($i->place_of_supply), $i->product?->name, $this->supplyLabel($i),
                    $i->taxable_total, $i->cgst_total, $i->sgst_total, $i->igst_total, $i->round_off, $i->grand_total, 'Issued', $i->amount_paid, $i->balance_due])->values()->all(),
            $this->totalRow($invoices->where('status', 'issued'), 7, ['taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'round_off', 'grand_total']),
        ]);

        $x->addSheet('Credit notes', [
            ['Date', 'Note No.', 'Against invoice', 'Party', 'GSTIN', 'Reason', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Round off', 'Note total', 'Status'],
            ...$notes->map(fn (Invoice $n) => [$this->d($n->invoice_date), $n->number, $n->originalInvoice?->number, $this->partyLabel($n), $n->bill_gstin, $n->reason,
                ...($n->status === 'cancelled' ? [0, 0, 0, 0, 0, 0, 'Cancelled'] : [$n->taxable_total, $n->cgst_total, $n->sgst_total, $n->igst_total, $n->round_off, $n->grand_total, 'Issued'])])->values()->all(),
            $this->totalRow($notes->where('status', 'issued'), 6, ['taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'round_off', 'grand_total']),
        ]);

        $lines = [];
        foreach ($this->docs->where('status', 'issued') as $doc) {
            $sign = $doc->isCreditNote() ? -1 : 1;
            foreach ($doc->items as $item) {
                $lines[] = [$this->d($doc->invoice_date), $doc->number, $doc->isCreditNote() ? 'Credit note' : 'Invoice', $this->partyLabel($doc), $item->description, $item->sac,
                    (float) $item->quantity, $item->unit, (float) $item->rate, $sign * (float) $item->taxable_value, (float) $item->gst_rate,
                    $sign * (float) $item->cgst, $sign * (float) $item->sgst, $sign * (float) $item->igst, $sign * (float) $item->amount];
            }
        }
        $x->addSheet('Sales lines (SAC)', [
            ['Date', 'Document', 'Type', 'Party', 'Description', 'SAC/HSN', 'Qty', 'Unit', 'Rate', 'Taxable value', 'GST %', 'CGST', 'SGST', 'IGST', 'Amount'],
            ...$lines,
        ]);

        $x->addSheet('Receipts', [
            ['Date', 'Invoice No.', 'Party', 'Received', 'TDS deducted', 'Total settled', 'Mode', 'Reference', 'Notes'],
            ...$this->payments->map(fn (Payment $p) => [$this->d($p->payment_date), $p->invoice?->number, $p->invoice ? $this->partyLabel($p->invoice) : '', $p->amount, $p->tds_amount,
                round($p->amount + $p->tds_amount, 2), strtoupper($p->method), $p->reference, $p->notes])->values()->all(),
            ['Total', '', '', round($this->payments->sum('amount'), 2), round($this->payments->sum('tds_amount'), 2), round($this->payments->sum(fn ($p) => $p->amount + $p->tds_amount), 2)],
        ]);

        $x->addSheet('Purchase register', [
            ['Bill date', 'Bill No.', 'Vendor', 'GSTIN', 'Category', 'Business line', 'Description', 'Supply', 'Reverse charge', 'Taxable value', 'GST %', 'CGST', 'SGST', 'IGST', 'Bill total', 'ITC eligible', 'ITC amount', 'ITC note', 'GSTR-2B', 'Payment', 'Paid on'],
            ...$this->purchases->map(fn (Purchase $p) => [$this->d($p->bill_date), $p->bill_number, $p->vendor?->name, $p->vendor_gstin, Purchase::CATEGORIES[$p->category] ?? $p->category,
                $p->product?->name, $p->description, $p->supply_type, $p->reverse_charge ? 'Y' : 'N', $p->taxable_value, $p->gst_rate, $p->cgst, $p->sgst, $p->igst, $p->total,
                $p->itc_eligible ? 'Y' : 'N', $p->itc_amount, $p->itc_note, $p->gstr2b_status ?? '', $p->payment_status, $this->d($p->paid_on)])->values()->all(),
            ['Total', '', '', '', '', '', '', '', '', round($this->purchases->sum('taxable_value'), 2), '', round($this->purchases->sum('cgst'), 2), round($this->purchases->sum('sgst'), 2),
                round($this->purchases->sum('igst'), 2), round($this->purchases->sum('total'), 2), '', round($this->purchases->sum('itc_amount'), 2)],
        ]);

        $x->addSheet('Expenses by category', [
            ['Category', 'Bills', 'Taxable value', 'GST paid', 'ITC claimable', 'Cost (taxable + GST not claimable)'],
            ...$this->purchases->groupBy('category')->map(fn ($rows, $cat) => [Purchase::CATEGORIES[$cat] ?? $cat, $rows->count(), round($rows->sum('taxable_value'), 2),
                round($rows->sum('tax_total'), 2), round($rows->sum('itc_amount'), 2), round($rows->sum(fn ($p) => $p->taxable_value + $p->tax_total - $p->itc_amount), 2)])->values()->all(),
        ]);

        $x->addSheet('Debtors '.$this->to->format('d-m-Y'), $this->debtorsSheet());

        return $x->output();
    }

    private function summarySheet(): array
    {
        $profile = BusinessProfile::current();
        $inv = $this->docs->where('type', Invoice::TYPE_INVOICE)->where('status', 'issued');
        $cn = $this->docs->where('type', Invoice::TYPE_CREDIT_NOTE)->where('status', 'issued');
        $r = $this->reports->build($this->from, $this->to)['gstr3b'];
        $tax = fn ($rows) => round($rows->sum(fn ($d) => $d->cgst_total + $d->sgst_total + $d->igst_total), 2);

        return [
            [($profile->trade_name ?: $profile->legal_name).' – books for '.$this->from->format('d M Y').' to '.$this->to->format('d M Y')],
            ['GSTIN', $profile->gstin ?? '', 'PAN', $profile->pan ?? ''],
            [],
            ['Sales', 'Count', 'Taxable value', 'GST', 'Total'],
            ['Invoices issued', $inv->count(), round($inv->sum('taxable_total'), 2), $tax($inv), round($inv->sum('grand_total'), 2)],
            ['Credit notes', $cn->count(), -round($cn->sum('taxable_total'), 2), -$tax($cn), -round($cn->sum('grand_total'), 2)],
            ['Net sales', '', round($inv->sum('taxable_total') - $cn->sum('taxable_total'), 2), round($tax($inv) - $tax($cn), 2), round($inv->sum('grand_total') - $cn->sum('grand_total'), 2)],
            ['Cancelled invoices', $this->docs->where('status', 'cancelled')->count()],
            [],
            ['Receipts', 'Count', 'Received', 'TDS deducted', 'Total settled'],
            ['From clients', $this->payments->count(), round($this->payments->sum('amount'), 2), round($this->payments->sum('tds_amount'), 2), round($this->payments->sum(fn ($p) => $p->amount + $p->tds_amount), 2)],
            [],
            ['Purchases & expenses', 'Count', 'Taxable value', 'GST paid', 'ITC claimable'],
            ['Bills', $this->purchases->count(), round($this->purchases->sum('taxable_value'), 2), round($this->purchases->sum('tax_total'), 2), round($this->purchases->sum('itc_amount'), 2)],
            [],
            ['GST (as in GSTR-3B)', 'IGST', 'CGST', 'SGST', 'Total'],
            ['Output tax', $r['tax_payable']['igst'], $r['tax_payable']['cgst'], $r['tax_payable']['sgst'], $r['tax_payable']['total']],
            ['Tax on reverse charge purchases', $r['inward_reverse_charge']['igst'], $r['inward_reverse_charge']['cgst'], $r['inward_reverse_charge']['sgst'],
                round($r['inward_reverse_charge']['igst'] + $r['inward_reverse_charge']['cgst'] + $r['inward_reverse_charge']['sgst'], 2)],
            ['Input tax credit', $r['itc_total']['igst'], $r['itc_total']['cgst'], $r['itc_total']['sgst'], $r['itc_total']['total']],
            ['Net payable (negative = credit carried forward)', $r['net_payable']['igst'], $r['net_payable']['cgst'], $r['net_payable']['sgst'], $r['net_payable']['total']],
            [],
            ['Prepared from LALogix admin on '.now()->format('d M Y H:i').'. Draft invoices are not included.'],
        ];
    }

    /** Invoices still unpaid on the last day of the period (payments and credit notes up to that day). */
    private function debtorsSheet(): array
    {
        $to = $this->to->toDateString();
        $open = Invoice::invoices()->where('status', 'issued')->whereDate('invoice_date', '<=', $to)
            ->withSum(['payments as paid_by' => fn ($q) => $q->whereDate('payment_date', '<=', $to)], 'amount')
            ->withSum(['payments as tds_by' => fn ($q) => $q->whereDate('payment_date', '<=', $to)], 'tds_amount')
            ->withSum(['creditNotes as credited_by' => fn ($q) => $q->where('status', 'issued')->whereDate('invoice_date', '<=', $to)], 'grand_total')
            ->orderBy('bill_name')->orderBy('invoice_date')->get()
            ->map(function (Invoice $i) {
                $settled = round((float) $i->paid_by + (float) $i->tds_by, 2);
                $credited = round((float) $i->credited_by, 2);
                $i->setAttribute('outstanding_at', round($i->grand_total - $settled - $credited, 2));
                $i->setAttribute('settled_at', $settled);
                $i->setAttribute('credited_at', $credited);

                return $i;
            })->filter(fn ($i) => $i->outstanding_at > 0.005);

        $rows = [['Party', 'GSTIN', 'Invoice No.', 'Date', 'Due date', 'Invoice total', 'Received (incl. TDS)', 'Credit notes', 'Outstanding', 'Days overdue']];
        foreach ($open as $i) {
            $due = $i->due_date ?? $i->invoice_date;
            $rows[] = [$this->partyLabel($i), $i->bill_gstin, $i->number, $this->d($i->invoice_date), $this->d($i->due_date), $i->grand_total, $i->settled_at, $i->credited_at,
                $i->outstanding_at, max(0, (int) $due->diffInDays($this->to, false))];
        }
        $rows[] = ['Total', '', '', '', '', '', '', '', round($open->sum('outstanding_at'), 2)];

        return $rows;
    }

    // ---- Tally ----------------------------------------------------------------------

    /** @return array{0: string, 1: string} masters XML, vouchers XML */
    public function tally(): array
    {
        $this->ledgers = $this->partyNames = [];
        $vouchers = [];

        foreach ($this->docs as $doc) {
            $vouchers[] = $doc->isCreditNote() ? $this->creditNoteVoucher($doc) : $this->salesVoucher($doc);
        }
        foreach ($this->payments as $p) {
            if ($p->invoice && ! $p->invoice->isCreditNote()) {
                $vouchers[] = $this->receiptVoucher($p);
            }
        }
        foreach ($this->purchases as $p) {
            $vouchers[] = $this->purchaseVoucher($p);
        }
        foreach ($this->vendorPayments as $p) {
            $vouchers[] = $this->paymentVoucher($p);
        }

        $masters = [];
        foreach ($this->ledgers as $name => $l) {
            $xml = '<LEDGER NAME="'.$this->e($name).'" ACTION="Create"><NAME.LIST><NAME>'.$this->e($name).'</NAME></NAME.LIST><PARENT>'.$this->e($l['parent']).'</PARENT>';
            foreach ($l['extra'] as $tag => $value) {
                $xml .= "<{$tag}>".$this->e($value)."</{$tag}>";
            }
            $masters[] = $xml.'</LEDGER>';
        }

        return [$this->envelope('All Masters', $masters), $this->envelope('Vouchers', $vouchers)];
    }

    private function salesVoucher(Invoice $inv): string
    {
        $party = $this->clientLedger($inv);
        if ($inv->status === 'cancelled') {
            return $this->voucher('Sales', $inv->invoice_date, $inv->number, 'Cancelled'.($inv->cancel_reason ? ": {$inv->cancel_reason}" : ''), [], $party, $inv, true);
        }
        $entries = [['ledger' => $party, 'amount' => -$inv->grand_total, 'party' => true, 'bill' => ['New Ref', $inv->number, $inv->due_date]]];
        foreach ($this->salesSplit($inv) as $ledger => $taxable) {
            $entries[] = ['ledger' => $ledger, 'amount' => $taxable];
        }
        foreach (self::HEADS as $head => $_) {
            if ((float) $inv->{"{$head}_total"} != 0.0) {
                $entries[] = ['ledger' => $this->taxLedger('Output', $head), 'amount' => (float) $inv->{"{$head}_total"}];
            }
        }
        if ((float) $inv->round_off != 0.0) {
            $entries[] = ['ledger' => $this->roundOff(), 'amount' => (float) $inv->round_off];
        }

        return $this->voucher('Sales', $inv->invoice_date, $inv->number, $this->narration($inv), $entries, $party, $inv);
    }

    private function creditNoteVoucher(Invoice $note): string
    {
        $party = $this->clientLedger($note);
        if ($note->status === 'cancelled') {
            return $this->voucher('Credit Note', $note->invoice_date, $note->number, 'Cancelled', [], $party, $note, true);
        }
        $ref = $note->originalInvoice?->number;
        $entries = [['ledger' => $party, 'amount' => $note->grand_total, 'party' => true, 'bill' => [$ref ? 'Agst Ref' : 'New Ref', $ref ?: $note->number, null]]];
        foreach ($this->salesSplit($note) as $ledger => $taxable) {
            $entries[] = ['ledger' => $ledger, 'amount' => -$taxable];
        }
        foreach (self::HEADS as $head => $_) {
            if ((float) $note->{"{$head}_total"} != 0.0) {
                $entries[] = ['ledger' => $this->taxLedger('Output', $head), 'amount' => -(float) $note->{"{$head}_total"}];
            }
        }
        if ((float) $note->round_off != 0.0) {
            $entries[] = ['ledger' => $this->roundOff(), 'amount' => -(float) $note->round_off];
        }
        $narration = trim(($ref ? "Against invoice {$ref}. " : '').($note->reason ?? ''));

        return $this->voucher('Credit Note', $note->invoice_date, $note->number, $narration, $entries, $party, $note);
    }

    private function receiptVoucher(Payment $p): string
    {
        $inv = $p->invoice;
        $party = $this->clientLedger($inv);
        $cashOrBank = $p->method === 'cash' ? $this->ledger(self::CASH, 'Cash-in-Hand') : $this->ledger(self::BANK, 'Bank Accounts');
        $entries = [['ledger' => $cashOrBank, 'amount' => -$p->amount]];
        if ($p->tds_amount > 0) {
            $entries[] = ['ledger' => $this->ledger(self::TDS, 'Current Assets'), 'amount' => -$p->tds_amount];
        }
        $entries[] = ['ledger' => $party, 'amount' => round($p->amount + $p->tds_amount, 2), 'party' => true, 'bill' => ['Agst Ref', $inv->number, null]];
        $narration = "Received against {$inv->number}".($p->reference ? ", ref {$p->reference}" : '').($p->tds_amount > 0 ? ', TDS Rs. '.number_format($p->tds_amount, 2) : '');

        return $this->voucher('Receipt', $p->payment_date, null, $narration, $entries, $party);
    }

    private function purchaseVoucher(Purchase $p): string
    {
        $expense = $this->expenseLedger($p);
        $tax = $p->tax_total;
        $claim = $p->itc_eligible && $tax > 0;
        $party = $this->purchaseParty($p);

        $entries = [['ledger' => $expense, 'amount' => -round($p->taxable_value + ($claim ? 0 : $tax), 2)]];
        if ($claim) {
            foreach (self::HEADS as $head => $_) {
                if ((float) $p->{$head} != 0.0) {
                    $entries[] = ['ledger' => $this->taxLedger('Input', $head), 'amount' => -(float) $p->{$head}];
                }
            }
        }
        $ref = $p->bill_number ?: 'P-'.$p->id;
        $entries[] = ['ledger' => $party, 'amount' => (float) $p->total, 'party' => $party !== self::BANK && $party !== self::CASH,
            'bill' => $party === self::BANK || $party === self::CASH ? null : ['New Ref', $ref, null]];
        if ($p->reverse_charge) {
            foreach (self::HEADS as $head => $_) {
                if ((float) $p->{$head} != 0.0) {
                    $entries[] = ['ledger' => $this->taxLedger('RCM Payable', $head), 'amount' => (float) $p->{$head}];
                }
            }
        }
        $narration = trim(($p->bill_number ? "Bill {$p->bill_number}. " : '').($p->description ?? '').($p->reverse_charge ? ' (reverse charge)' : '').(! $p->itc_eligible && $tax > 0 ? ' – ITC not claimed'.($p->itc_note ? ": {$p->itc_note}" : '') : ''));

        return $this->voucher('Purchase', $p->bill_date, 'P/'.$p->id, $narration, $entries, $party, null, false, $p->bill_number);
    }

    private function paymentVoucher(Purchase $p): string
    {
        $party = $this->purchaseParty($p);
        $cashOrBank = $p->payment_method === 'cash' ? $this->ledger(self::CASH, 'Cash-in-Hand') : $this->ledger(self::BANK, 'Bank Accounts');
        $ref = $p->bill_number ?: 'P-'.$p->id;

        return $this->voucher('Payment', $p->paid_on, null, "Paid {$p->vendor->name} against {$ref}", [
            ['ledger' => $party, 'amount' => -(float) $p->total, 'party' => true, 'bill' => ['Agst Ref', $ref, null]],
            ['ledger' => $cashOrBank, 'amount' => (float) $p->total],
        ], $party);
    }

    /**
     * @param list<array{ledger: string, amount: float, party?: bool, bill?: ?array}> $entries amount > 0 = credit
     */
    private function voucher(string $type, Carbon $date, ?string $number, string $narration, array $entries, string $party, ?Invoice $doc = null, bool $cancelled = false, ?string $reference = null): string
    {
        // Paise-level differences (e.g. rounding inside line items) go to Round Off so the voucher always balances.
        $diff = round(array_sum(array_column($entries, 'amount')), 2);
        if ($entries && $diff != 0.0) {
            $entries[] = ['ledger' => $this->roundOff(), 'amount' => -$diff];
        }

        $x = '<VOUCHER VCHTYPE="'.$this->e($type).'" ACTION="Create" OBJVIEW="Accounting Voucher View">'
            .'<DATE>'.$date->format('Ymd').'</DATE><EFFECTIVEDATE>'.$date->format('Ymd').'</EFFECTIVEDATE>'
            .'<VOUCHERTYPENAME>'.$this->e($type).'</VOUCHERTYPENAME>'
            .($number ? '<VOUCHERNUMBER>'.$this->e($number).'</VOUCHERNUMBER>' : '')
            .($reference ? '<REFERENCE>'.$this->e($reference).'</REFERENCE>' : '')
            .'<PARTYLEDGERNAME>'.$this->e($party).'</PARTYLEDGERNAME><PERSISTEDVIEW>Accounting Voucher View</PERSISTEDVIEW>';
        if ($doc) {
            $x .= ($doc->bill_gstin ? '<PARTYGSTIN>'.$this->e($doc->bill_gstin).'</PARTYGSTIN>' : '')
                .'<PLACEOFSUPPLY>'.$this->e(GstStates::name($doc->place_of_supply)).'</PLACEOFSUPPLY>'
                .($doc->bill_state_code ? '<STATENAME>'.$this->e(GstStates::name($doc->bill_state_code)).'</STATENAME>' : '')
                .'<COUNTRYOFRESIDENCE>'.$this->e($doc->bill_country ?: 'India').'</COUNTRYOFRESIDENCE>';
        }
        if ($cancelled) {
            $x .= '<ISCANCELLED>Yes</ISCANCELLED>';
        }
        $x .= '<NARRATION>'.$this->e($narration).'</NARRATION>';

        foreach ($entries as $en) {
            $amount = round($en['amount'], 2);
            $x .= '<ALLLEDGERENTRIES.LIST><LEDGERNAME>'.$this->e($en['ledger']).'</LEDGERNAME>'
                .'<ISDEEMEDPOSITIVE>'.($amount < 0 ? 'Yes' : 'No').'</ISDEEMEDPOSITIVE>'
                .(! empty($en['party']) ? '<ISPARTYLEDGER>Yes</ISPARTYLEDGER>' : '')
                .'<AMOUNT>'.$this->amt($amount).'</AMOUNT>';
            if (! empty($en['bill'])) {
                [$billType, $name, $due] = $en['bill'];
                $x .= '<BILLALLOCATIONS.LIST><NAME>'.$this->e($name).'</NAME><BILLTYPE>'.$billType.'</BILLTYPE>'
                    .($due && $billType === 'New Ref' ? '<BILLCREDITPERIOD>'.max(0, (int) $date->diffInDays($due, false)).' Days</BILLCREDITPERIOD>' : '')
                    .'<AMOUNT>'.$this->amt($amount).'</AMOUNT></BILLALLOCATIONS.LIST>';
            }
            $x .= '</ALLLEDGERENTRIES.LIST>';
        }

        return $x.'</VOUCHER>';
    }

    private function envelope(string $report, array $messages): string
    {
        $company = trim((string) BusinessProfile::current()->tally_company);

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<ENVELOPE><HEADER><TALLYREQUEST>Import Data</TALLYREQUEST></HEADER><BODY><IMPORTDATA>'
            .'<REQUESTDESC><REPORTNAME>'.$report.'</REPORTNAME>'
            .($company !== '' ? '<STATICVARIABLES><SVCURRENTCOMPANY>'.$this->e($company).'</SVCURRENTCOMPANY></STATICVARIABLES>' : '')
            .'</REQUESTDESC><REQUESTDATA>'
            .implode("\n", array_map(fn ($m) => '<TALLYMESSAGE xmlns:UDF="TallyUDF">'.$m.'</TALLYMESSAGE>', $messages))
            .'</REQUESTDATA></IMPORTDATA></BODY></ENVELOPE>'."\n";
    }

    // ---- Ledger names ---------------------------------------------------------------

    /** Taxable value per sales ledger ("Sales @ 18%", "Export Sales (LUT)", "Sales - Exempt / Nil"). */
    private function salesSplit(Invoice $doc): array
    {
        $out = [];
        foreach ($doc->items as $item) {
            $name = match (true) {
                $doc->supply_type === 'export' => $doc->export_with_lut ? 'Export Sales (LUT)' : 'Export Sales',
                (float) $item->gst_rate === 0.0 => 'Sales - Exempt / Nil',
                default => 'Sales @ '.$this->rate($item->gst_rate).'%',
            };
            $name = $this->ledger($name, 'Sales Accounts');
            $out[$name] = round(($out[$name] ?? 0) + (float) $item->taxable_value, 2);
        }

        return $out;
    }

    private function taxLedger(string $kind, string $head): string
    {
        return $this->ledger("{$kind} ".strtoupper($head), 'Duties & Taxes', ['TAXTYPE' => 'GST', 'GSTDUTYHEAD' => self::HEADS[$head]]);
    }

    private function roundOff(): string
    {
        return $this->ledger(self::ROUND_OFF, 'Indirect Expenses');
    }

    private function expenseLedger(Purchase $p): string
    {
        return $this->ledger(Purchase::CATEGORIES[$p->category] ?? 'Other expenses', $p->category === 'equipment' ? 'Fixed Assets' : 'Indirect Expenses');
    }

    private function clientLedger(Invoice $doc): string
    {
        $name = $this->partyLabel($doc);

        return $this->partyLedger('client:'.$doc->client_id, $name, 'Sundry Debtors', $doc->bill_gstin, $doc->bill_state_code, $doc->bill_country);
    }

    /** Vendor ledger; bills without a vendor go to Bank/Cash when paid, else a common creditors ledger. */
    private function purchaseParty(Purchase $p): string
    {
        if ($p->vendor) {
            return $this->partyLedger('vendor:'.$p->vendor_id, $p->vendor->name, 'Sundry Creditors', $p->vendor->gstin ?: $p->vendor_gstin, $p->vendor->state_code, $p->vendor->country);
        }
        if ($p->payment_status === 'paid') {
            return $p->payment_method === 'cash' ? $this->ledger(self::CASH, 'Cash-in-Hand') : $this->ledger(self::BANK, 'Bank Accounts');
        }

        return $this->ledger(self::OTHER_CREDITORS, 'Sundry Creditors', ['ISBILLWISEON' => 'Yes']);
    }

    /** Tally ledger names are unique across all ledgers, so a second party with the same name gets its GSTIN (or a number) appended. */
    private function partyLedger(string $key, string $name, string $parent, ?string $gstin, ?string $state, ?string $country): string
    {
        if (! isset($this->partyNames[$key])) {
            $name = trim($name) ?: 'Party';
            $candidate = $name;
            $n = 1;
            while (isset($this->ledgers[$candidate])) {
                $n++;
                $candidate = $n === 2 && $gstin ? "{$name} ({$gstin})" : "{$name} ({$n})";
            }
            $this->partyNames[$key] = $candidate;
            $this->ledger($candidate, $parent, array_filter([
                'ISBILLWISEON' => 'Yes',
                'COUNTRYNAME' => $country ?: 'India',
                'LEDSTATENAME' => $state && $state !== GstStates::OUTSIDE_INDIA ? GstStates::name($state) : null,
                'GSTREGISTRATIONTYPE' => $gstin ? 'Regular' : ($country && strcasecmp($country, 'India') !== 0 ? 'Overseas' : 'Unregistered/Consumer'),
                'PARTYGSTIN' => $gstin,
            ]));
        }

        return $this->partyNames[$key];
    }

    private function ledger(string $name, string $parent, array $extra = []): string
    {
        $this->ledgers[$name] ??= ['parent' => $parent, 'extra' => $extra];

        return $name;
    }

    // ---- Formatting -----------------------------------------------------------------

    private function partyLabel(Invoice $doc): string
    {
        return (string) $doc->bill_name; // the billed party (company when set); bill_company holds the contact person
    }

    private function narration(Invoice $inv): string
    {
        $first = $inv->items->first()?->description;
        $more = $inv->items->count() > 1 ? ' and '.($inv->items->count() - 1).' more' : '';

        return trim(($inv->product?->name ? "{$inv->product->name}: " : '').mb_substr((string) $first, 0, 200).$more);
    }

    private function supplyLabel(Invoice $doc): string
    {
        return match ($doc->supply_type) {
            'export' => $doc->export_with_lut ? 'Export (LUT)' : 'Export',
            'inter' => 'Inter-state',
            default => 'Intra-state',
        }.($doc->reverse_charge ? ', RCM' : '');
    }

    private function totalRow(Collection $rows, int $offset, array $fields): array
    {
        return [...array_fill(0, $offset, ''), ...array_map(fn ($f) => round($rows->sum($f), 2), $fields)];
    }

    private function d(?Carbon $date): string
    {
        return $date ? $date->format('d-m-Y') : '';
    }

    private function rate(float|string $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }

    private function amt(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private function e(?string $s): string
    {
        // XML 1.0 forbids most control characters; Tally rejects the whole file on one.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function readme(array $parts): string
    {
        $lines = [
            'Books for '.$this->from->format('d M Y').' to '.$this->to->format('d M Y'),
            'Exported from the LALogix admin on '.now()->format('d M Y H:i').'. Draft invoices are never included.',
            '',
        ];
        if (in_array('books', $parts, true)) {
            $lines[] = 'Books-*.xlsx      Summary, sales register, credit notes, sales lines by SAC, receipts, purchase register, expenses by category, and debtors outstanding on the last day.';
        }
        if (in_array('gst', $parts, true)) {
            $lines[] = 'GST-report-*.xlsx GSTR-3B summary and GSTR-1 tables laid out like the GST offline tool sheets.';
        }
        if (in_array('pdfs', $parts, true)) {
            $lines[] = 'Invoices/, Credit notes/  PDF copies of every document in the period (issued and cancelled).';
        }
        if (in_array('tally', $parts, true)) {
            array_push($lines, '',
                'TALLY PRIME IMPORT',
                '1. Open the company in Tally, then Gateway of Tally > Import > Masters and choose Tally/1-Masters.xml.',
                '   This creates the ledgers used: parties (Sundry Debtors / Creditors, bill-wise on), Sales @ x%, Output/Input CGST/SGST/IGST',
                '   (Duties & Taxes), RCM Payable, Bank Account, Cash, TDS Receivable, Round Off and expense ledgers. Ledgers that already exist',
                '   are reported as errors and left unchanged, which is fine.',
                '   Tip: if your bank ledger has a different name, rename "Bank Account" in this file (and in 2-Vouchers.xml) before importing.',
                '2. Gateway of Tally > Import > Transactions and choose Tally/2-Vouchers.xml (Sales, Credit Note, Receipt, Purchase, Payment).',
                '   Set "Behaviour of import if voucher already exists" to Ignore duplicates when importing a period again.',
                '3. Receipts and payments are linked to invoices and bills by reference (bill-wise), so outstanding reports match.',
            );
        }

        return implode("\r\n", $lines)."\r\n";
    }
}
