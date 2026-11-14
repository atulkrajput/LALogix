<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\GstExport;
use App\Services\Billing\GstReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private GstReportService $reports) {}

    public function gst(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json($this->reports->build($from, $to));
    }

    /** Excel download (web route, session auth). format=gstr1 (offline-tool sheets only) | full */
    public function download(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $report = $this->reports->build($from, $to);
        $full = $request->query('format') === 'full';
        $name = ($full ? 'GST-report-' : 'GSTR1-').$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.xlsx';

        return response($full ? GstExport::full($report) : GstExport::gstr1($report), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->startOfDay();
        if ($from->diffInDays($to) > 400) {
            abort(response()->json(['error' => 'Choose a period of at most one year.'], 422));
        }

        return [$from, $to];
    }
}
