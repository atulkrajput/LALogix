<?php

namespace App\Services\Billing;

use App\Support\GstStates;
use App\Support\SimpleXlsx;
use Illuminate\Support\Carbon;

/**
 * Excel exports of a GstReportService report.
 *
 * gstr1(): sheets named and laid out like the GST offline tool's GSTR-1 Excel
 * template (summary rows 1-3, column headers on row 4, data from row 5), so the
 * file can be imported into the offline tool or used to copy figures into the portal.
 * full():  the same plus the GSTR-3B summary, product breakdown and checklist.
 */
class GstExport
{
    public static function gstr1(array $r): string
    {
        $x = new SimpleXlsx();
        foreach (self::gstr1Sheets($r) as $name => $rows) {
            $x->addSheet($name, $rows, [0, 1, 3]);
        }

        return $x->output();
    }

    public static function full(array $r): string
    {
        $x = new SimpleXlsx();
        $x->addSheet('Summary', self::summarySheet($r), [0, 4, 13, 21]);
        foreach (self::gstr1Sheets($r) as $name => $rows) {
            $x->addSheet($name, $rows, [0, 1, 3]);
        }

        return $x->output();
    }

    /** @return array<string, list<list<mixed>>> */
    private static function gstr1Sheets(array $r): array
    {
        $sum = fn (array $rows, string $k) => round(array_sum(array_column($rows, $k)), 2);
        $count = fn (array $rows, string $k) => count(array_unique(array_column($rows, $k)));

        $b2b = $r['b2b'];
        $sheets['b2b,sez,de'] = [
            ['Summary For B2B, SEZ, DE (4A, 4B, 6B, 6C)'],
            ['No. of Recipients', '', 'No. of Invoices', '', 'Total Invoice Value', '', '', '', '', '', '', 'Total Taxable Value', 'Total Cess'],
            [$count($b2b, 'gstin'), '', $count($b2b, 'number'), '', self::invoiceValueTotal($b2b), '', '', '', '', '', '', $sum($b2b, 'taxable'), 0],
            ['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...array_map(fn ($l) => [$l['gstin'], $l['name'], $l['number'], self::date($l['date']), $l['value'], self::pos($l['place_of_supply']), $l['reverse_charge'] ? 'Y' : 'N', '', 'Regular B2B', '', $l['rate'], $l['taxable'], 0], $b2b),
        ];

        $b2cl = $r['b2cl'];
        $sheets['b2cl'] = [
            ['Summary For B2CL(5)'],
            ['No. of Invoices', '', 'Total Inv Value', '', '', '', 'Total Taxable Value', 'Total Cess'],
            [$count($b2cl, 'number'), '', self::invoiceValueTotal($b2cl), '', '', '', $sum($b2cl, 'taxable'), 0],
            ['Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'],
            ...array_map(fn ($l) => [$l['number'], self::date($l['date']), $l['value'], self::pos($l['place_of_supply']), '', $l['rate'], $l['taxable'], 0, ''], $b2cl),
        ];

        $b2cs = $r['b2cs'];
        $sheets['b2cs'] = [
            ['Summary For B2CS(7)'],
            ['', '', '', '', 'Total Taxable  Value', 'Total Cess'],
            ['', '', '', '', $sum($b2cs, 'taxable'), 0],
            ['Type', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN'],
            ...array_map(fn ($l) => ['OE', self::pos($l['place_of_supply']), '', $l['rate'], $l['taxable'], 0, ''], $b2cs),
        ];

        $cdnr = $r['cdnr'];
        $sheets['cdnr'] = [
            ['Summary For CDNR(9B)'],
            ['No. of Recipients', '', 'No. of Notes', '', '', '', '', 'Total Note Value', '', '', 'Total Taxable Value', 'Total Cess'],
            [$count($cdnr, 'gstin'), '', $count($cdnr, 'number'), '', '', '', '', self::invoiceValueTotal($cdnr), '', '', $sum($cdnr, 'taxable'), 0],
            ['GSTIN/UIN of Recipient', 'Receiver Name', 'Note Number', 'Note Date', 'Note Type', 'Place Of Supply', 'Reverse Charge', 'Note Supply Type', 'Note Value', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...array_map(fn ($l) => [$l['gstin'], $l['name'], $l['number'], self::date($l['date']), 'C', self::pos($l['place_of_supply']), $l['reverse_charge'] ? 'Y' : 'N', 'Regular B2B', $l['value'], '', $l['rate'], $l['taxable'], 0], $cdnr),
        ];

        $cdnur = $r['cdnur'];
        $sheets['cdnur'] = [
            ['Summary For CDNUR(9B)'],
            ['', 'No. of Notes/Vouchers', '', '', '', 'Total Note Value', '', '', 'Total Taxable Value', 'Total Cess'],
            ['', $count($cdnur, 'number'), '', '', '', self::invoiceValueTotal($cdnur), '', '', $sum($cdnur, 'taxable'), 0],
            ['UR Type', 'Note Number', 'Note Date', 'Note Type', 'Place Of Supply', 'Note Value', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...array_map(fn ($l) => [$l['ur_type'], $l['number'], self::date($l['date']), 'C', $l['ur_type'] === 'B2CL' ? self::pos($l['place_of_supply']) : '', $l['value'], '', $l['rate'], $l['taxable'], 0], $cdnur),
        ];

        $exp = $r['exp'];
        $sheets['exp'] = [
            ['Summary For EXP(6)'],
            ['', 'No. of Invoices', '', 'Total Invoice Value', '', 'No. of Shipping Bill', '', '', 'Total Taxable Value', 'Total Cess'],
            ['', $count($exp, 'number'), '', self::invoiceValueTotal($exp), '', 0, '', '', $sum($exp, 'taxable'), 0],
            ['Export Type', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Port Code', 'Shipping Bill Number', 'Shipping Bill Date', 'Rate', 'Taxable Value', 'Cess Amount'],
            ...array_map(fn ($l) => [$l['export_type'], $l['number'], self::date($l['date']), $l['value'], '', '', '', $l['rate'], $l['taxable'], 0], $exp),
        ];

        foreach (['hsn(b2b)' => $r['hsn_b2b'], 'hsn(b2c)' => $r['hsn_b2c']] as $name => $hsn) {
            $sheets[$name] = [
                ['Summary For HSN(12)'],
                ['No. of HSN', '', '', '', 'Total Value', '', 'Total Taxable Value', 'Total Integrated Tax', 'Total Central Tax', 'Total State/UT Tax', 'Total Cess'],
                [count($hsn), '', '', '', $sum($hsn, 'value'), '', $sum($hsn, 'taxable'), $sum($hsn, 'igst'), $sum($hsn, 'cgst'), $sum($hsn, 'sgst'), 0],
                ['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount'],
                ...array_map(fn ($l) => [$l['hsn'], $l['description'], $l['uqc'], 0, $l['value'], $l['rate'], $l['taxable'], $l['igst'], $l['cgst'], $l['sgst'], 0], $hsn),
            ];
        }

        $docs = $r['docs'];
        $sheets['docs'] = [
            ['Summary of documents issued during the tax period (13)'],
            ['', '', '', 'Total Number', 'Total Cancelled'],
            ['', '', '', array_sum(array_column($docs, 'total')), array_sum(array_column($docs, 'cancelled'))],
            ['Nature of Document', 'Sr. No. From', 'Sr. No. To', 'Total Number', 'Cancelled'],
            ...array_map(fn ($d) => [$d['nature'], $d['from'], $d['to'], $d['total'], $d['cancelled']], $docs),
        ];

        return $sheets;
    }

    private static function summarySheet(array $r): array
    {
        $g = $r['gstr3b'];
        $row = fn (string $label, array $v) => [$label, $v['taxable'], $v['igst'], $v['cgst'], $v['sgst'], $v['cess']];
        $rows = [
            ['GST report: '.$r['period']['label'].' ('.($r['seller']['gstin'] ?? 'GSTIN not set').')'],
            ['Period', $r['period']['from'].' to '.$r['period']['to']],
            ['Documents', $r['counts']['invoices'].' invoices, '.$r['counts']['credit_notes'].' credit notes, '.$r['counts']['cancelled'].' cancelled'],
            [],
            ['GSTR-3B 3.1', 'Taxable value', 'IGST', 'CGST', 'SGST/UTGST', 'Cess'],
            $row('(a) Outward taxable supplies (other than zero rated, nil and exempted)', $g['taxable_outward']),
            $row('(b) Outward taxable supplies (zero rated)', $g['zero_rated']),
            $row('(c) Other outward supplies (nil rated, exempted)', $g['nil_exempt']),
            $row('Outward supplies on reverse charge (tax paid by recipient)', $g['reverse_charge_outward']),
            [],
            ['Tax payable (before ITC)', '', $g['tax_payable']['igst'], $g['tax_payable']['cgst'], $g['tax_payable']['sgst'], 0],
            ['Total', $g['tax_payable']['total']],
            [],
            ['GSTR-3B 3.2 Inter-state supplies to unregistered persons', 'Taxable value', 'IGST'],
        ];
        foreach ($g['interstate_unregistered'] as $u) {
            $rows[] = [self::pos($u['place_of_supply']), $u['taxable'], $u['igst']];
        }
        while (count($rows) < 20) {
            $rows[] = [];
        }
        $rows[] = [];
        $rows[] = ['Product', 'Invoices', 'Credit notes', 'Taxable value', 'GST', 'Total'];
        foreach ($r['by_product'] as $p) {
            $rows[] = [$p['product'], $p['invoices'], $p['credit_notes'], $p['taxable'], $p['tax'], $p['total']];
        }
        if (isset($g['itc_total'])) {
            $rows[] = [];
            $rows[] = ['GSTR-3B (purchases)', 'Taxable value', 'IGST', 'CGST', 'SGST/UTGST', 'Cess'];
            $rows[] = $row('3.1(d) Inward supplies liable to reverse charge', $g['inward_reverse_charge']);
            $rows[] = $row('4A(3) ITC: inward supplies liable to reverse charge', $g['itc_reverse_charge']);
            $rows[] = $row('4A(5) ITC: all other', $g['itc_other']);
            $rows[] = ['Net GST payable (output + RCM − ITC)', '', $g['net_payable']['igst'], $g['net_payable']['cgst'], $g['net_payable']['sgst'], 0];
            $rows[] = ['Net total', $g['net_payable']['total']];
        }
        if ($r['warnings']) {
            $rows[] = [];
            $rows[] = ['Checklist'];
            foreach ($r['warnings'] as $w) {
                $rows[] = [strtoupper($w['level']), $w['message']];
            }
        }

        return $rows;
    }

    /** Invoice value counted once per document (rows are split by rate). */
    private static function invoiceValueTotal(array $rows): float
    {
        $seen = [];
        foreach ($rows as $l) {
            $seen[$l['number']] = $l['value'];
        }

        return round(array_sum($seen), 2);
    }

    /** dd-Mon-yyyy, as the offline tool template expects. */
    private static function date(string $iso): string
    {
        return Carbon::parse($iso)->format('d-M-Y');
    }

    /** "29-Karnataka" place-of-supply format. */
    private static function pos(string $code): string
    {
        return $code.'-'.GstStates::name($code);
    }
}
