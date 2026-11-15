<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Services\Billing\AccountingExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The accountant's pack (Excel books, GST report, Tally XML, PDFs) for a period. */
class ExportController extends Controller
{
    public function __construct(private AccountingExport $export) {}

    /** What the pack for the period will contain. */
    public function preview(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json([
            'counts' => $this->export->load($from, $to)->counts(),
            'filename' => $this->export->filename(),
            'tally_company' => BusinessProfile::current()->tally_company,
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['tally_company' => 'nullable|string|max:255']);
        BusinessProfile::current()->update(['tally_company' => trim((string) ($data['tally_company'] ?? '')) ?: null]);

        return response()->json(['success' => true]);
    }

    /** Web route (session auth): the zip. */
    public function download(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $parts = $request->validate([
            'parts' => 'required|array|min:1',
            'parts.*' => [Rule::in(AccountingExport::PARTS)],
        ])['parts'];

        $this->export->load($from, $to);
        if (in_array('pdfs', $parts, true) && $this->export->counts()['pdfs'] > AccountingExport::MAX_PDFS) {
            abort(422, 'Too many documents for one download with PDFs ('.AccountingExport::MAX_PDFS.' max). Choose a shorter period or leave out the PDFs.');
        }
        @set_time_limit(300);

        return response($this->export->zip($parts), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$this->export->filename().'"',
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        if ($from->diffInDays($to) > 400) {
            abort(response()->json(['error' => 'Choose a period of at most one year.'], 422));
        }

        return [$from, $to];
    }
}
