<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Billing\ExternalInvoiceService;
use App\Support\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV import of invoices a product has already issued (for products that can't
 * call the ingest API yet, or to back-fill past months). One row per invoice
 * line; rows with the same invoice_number form one invoice.
 */
class ImportController extends Controller
{
    public const COLUMNS = [
        'invoice_number', 'invoice_date', 'customer_name', 'customer_company', 'customer_gstin', 'customer_email',
        'customer_phone', 'customer_address', 'customer_city', 'customer_state', 'customer_pincode', 'customer_country',
        'place_of_supply', 'description', 'sac', 'quantity', 'unit', 'rate', 'discount', 'gst_rate', 'invoice_total',
        'paid_amount', 'paid_date', 'payment_reference',
    ];

    public function template(): Response
    {
        $rows = [
            self::COLUMNS,
            ['VIVK/26-27/0001', '2026-11-01', 'Ravi Kumar', 'Acme Pvt Ltd', '27AAPFU0939F1ZV', 'ravi@example.com', '', '42 Nariman Point', 'Mumbai', '27', '400021', 'India', '', 'Annual subscription – Pro plan', '998314', '1', 'NOS', '9999', '0', '18', '11798.82', '11798.82', '2026-11-01', 'pay_ABC123'],
            ['VIVK/26-27/0002', '02-11-2026', 'Priya Sharma', '', '', 'priya@example.com', '', '', 'Pune', 'Maharashtra', '', 'India', '', 'Monthly subscription – Basic', '998314', '1', 'NOS', '499', '0', '18', '', '', '', ''],
        ];
        $csv = implode("\r\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', $v).'"', $r)), $rows))."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lalogix-invoice-import-template.csv"',
        ]);
    }

    public function store(Request $request, ExternalInvoiceService $service): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'file' => 'required|file|max:5120',
        ], ['file.max' => 'The CSV must be smaller than 5 MB.']);

        $product = Product::findOrFail($request->integer('product_id'));
        [$invoices, $errors] = $this->parse($request->file('file')->getRealPath());

        $summary = ['created' => 0, 'unchanged' => 0, 'rejected' => 0, 'results' => []];
        foreach ($errors as $e) {
            $summary['rejected']++;
            $summary['results'][] = $e;
        }
        foreach ($invoices as $number => $payload) {
            try {
                $result = $service->recordInvoice($product, $payload, 'import', $request->ip());
                $summary[$result['outcome']]++;
                $summary['results'][] = ['number' => $number, 'outcome' => $result['outcome'], 'id' => $result['invoice']->id];
            } catch (ValidationException $e) {
                $summary['rejected']++;
                $summary['results'][] = ['number' => $number, 'outcome' => 'rejected', 'message' => collect($e->errors())->flatten()->implode(' ')];
            } catch (Throwable $e) {
                report($e);
                $summary['rejected']++;
                $summary['results'][] = ['number' => $number, 'outcome' => 'rejected', 'message' => 'Unexpected error while saving this invoice.'];
            }
        }

        return response()->json($summary);
    }

    /** @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function parse(string $path): array
    {
        $fh = fopen($path, 'r');
        $first = fgets($fh) ?: '';
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first); // Excel's UTF-8 BOM
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim(str_replace(' ', '_', $h))), str_getcsv($first, $delimiter));

        foreach (['invoice_number', 'invoice_date', 'customer_name', 'description', 'rate', 'gst_rate'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($fh);
                throw ValidationException::withMessages(['file' => "The CSV is missing the \"{$required}\" column. Download the template to see the expected columns."]);
            }
        }

        $invoices = [];
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[$col] = trim((string) ($cells[$i] ?? ''));
            }
            $number = $row['invoice_number'];
            if ($number === '') {
                $errors[] = ['number' => "row {$line}", 'outcome' => 'rejected', 'message' => 'invoice_number is empty.'];
                continue;
            }
            $date = $this->date($row['invoice_date']);
            if (! $date) {
                $errors[] = ['number' => $number, 'outcome' => 'rejected', 'message' => "Row {$line}: invoice_date \"{$row['invoice_date']}\" is not a date (use YYYY-MM-DD or DD-MM-YYYY)."];
                continue;
            }

            $invoices[$number] ??= array_filter([
                'external_id' => $number,
                'number' => $number,
                'date' => $date,
                'customer' => array_filter([
                    'name' => $row['customer_name'],
                    'company' => $row['customer_company'] ?? '',
                    'gstin' => $row['customer_gstin'] ?? '',
                    'email' => $row['customer_email'] ?? '',
                    'phone' => $row['customer_phone'] ?? '',
                    'address_line1' => $row['customer_address'] ?? '',
                    'city' => $row['customer_city'] ?? '',
                    'state_code' => $this->state($row['customer_state'] ?? ''),
                    'pincode' => $row['customer_pincode'] ?? '',
                    'country' => $row['customer_country'] ?? '',
                ], fn ($v) => $v !== '' && $v !== null),
                'place_of_supply' => $this->state($row['place_of_supply'] ?? ''),
                'total' => ($row['invoice_total'] ?? '') !== '' ? $this->number($row['invoice_total']) : null,
                'payment' => ($row['paid_amount'] ?? '') !== '' ? [
                    'amount' => $this->number($row['paid_amount']),
                    'date' => $this->date($row['paid_date'] ?? '') ?: $date,
                    'method' => 'other',
                    'reference' => ($row['payment_reference'] ?? '') ?: null,
                ] : null,
                'items' => [],
            ], fn ($v) => $v !== null && $v !== '');
            $invoices[$number]['items'][] = [
                'description' => $row['description'],
                'sac' => ($row['sac'] ?? '') ?: null,
                'quantity' => ($row['quantity'] ?? '') !== '' ? $this->number($row['quantity']) : 1,
                'unit' => ($row['unit'] ?? '') ?: 'NOS',
                'rate' => $this->number($row['rate']),
                'discount' => ($row['discount'] ?? '') !== '' ? $this->number($row['discount']) : 0,
                'gst_rate' => $this->number(rtrim($row['gst_rate'], '%')),
            ];
        }
        fclose($fh);

        return [$invoices, $errors];
    }

    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'd.m.Y', 'd-M-Y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!'.$format, $value);
                if ($d && $d->format($format) === $value) {
                    return $d->toDateString();
                }
            } catch (Throwable) {
            }
        }

        return null;
    }

    /** Accepts "29", "29-Karnataka" or "Karnataka". */
    private function state(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\b/', $value, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        foreach (GstStates::ALL as $code => $name) {
            if (strcasecmp($name, $value) === 0) {
                return (string) $code;
            }
        }

        return $value; // let validation report it
    }

    /** "1,23,456.50" / "₹ 1,180" → float */
    private function number(string $value): float
    {
        return (float) preg_replace('/[^0-9.\-]/', '', $value);
    }
}
