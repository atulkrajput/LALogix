<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Support\Gstin;
use App\Support\GstStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Business profile (seller details on invoices) + shared billing lookups. */
class SettingsController extends Controller
{
    public const GST_RATES = [0, 0.1, 0.25, 1, 1.5, 3, 5, 6, 7.5, 12, 18, 28];

    public function show(): JsonResponse
    {
        return response()->json([
            'profile' => BusinessProfile::current(),
            'states' => GstStates::options(),
            'gstRates' => self::GST_RATES,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge([
            'gstin' => Gstin::normalize($request->input('gstin')),
            'pan' => $request->filled('pan') ? strtoupper(trim($request->input('pan'))) : null,
            'bank_ifsc' => $request->filled('bank_ifsc') ? strtoupper(trim($request->input('bank_ifsc'))) : null,
        ]);

        $data = $request->validate([
            'legal_name' => 'required|string|max:255',
            'trade_name' => 'nullable|string|max:255',
            'gstin' => ['nullable', 'string', function ($attr, $value, $fail) {
                if ($value && ! Gstin::isValid($value)) {
                    $fail('That GSTIN is not valid (format or check digit).');
                }
            }],
            'pan' => ['nullable', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'state_code' => ['required', Rule::in(array_keys(GstStates::ALL))],
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:40',
            'bank_ifsc' => ['nullable', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'bank_branch' => 'nullable|string|max:255',
            'upi_id' => 'nullable|string|max:255',
            'lut_number' => 'nullable|string|max:255',
            'payment_terms_days' => 'required|integer|min:0|max:365',
            'round_off_total' => 'boolean',
            'invoice_notes' => 'nullable|string|max:2000',
            'invoice_terms' => 'nullable|string|max:2000',
            'logo' => ['nullable', 'string', 'max:550000', 'regex:/^data:image\/(png|jpeg);base64,/'],
            'signature' => ['nullable', 'string', 'max:550000', 'regex:/^data:image\/(png|jpeg);base64,/'],
        ], [
            'logo.max' => 'The logo must be smaller than 400 KB.',
            'signature.max' => 'The signature must be smaller than 400 KB.',
            'logo.regex' => 'The logo must be a PNG or JPG image.',
            'signature.regex' => 'The signature must be a PNG or JPG image.',
            'bank_ifsc.regex' => 'That IFSC code is not valid.',
            'pan.regex' => 'That PAN is not valid.',
        ]);

        if ($data['gstin'] ?? null) {
            if (Gstin::stateCode($data['gstin']) !== $data['state_code']) {
                return response()->json(['error' => 'The GST state must match the first two digits of your GSTIN ('.GstStates::label(Gstin::stateCode($data['gstin'])).').'], 422);
            }
            $data['pan'] = $data['pan'] ?: Gstin::pan($data['gstin']);
        }

        $profile = BusinessProfile::current();
        $profile->update($data);

        return response()->json(['profile' => $profile->fresh()]);
    }
}
