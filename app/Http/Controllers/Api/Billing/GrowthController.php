<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\GrowthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GrowthController extends Controller
{
    public function show(Request $request, GrowthService $growth): JsonResponse
    {
        $data = $request->validate(['range' => ['nullable', Rule::in(['6m', '12m', 'fy', 'prev_fy'])]]);

        return response()->json($growth->build($data['range'] ?? '12m'));
    }
}
