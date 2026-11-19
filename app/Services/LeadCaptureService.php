<?php

namespace App\Services;

use App\Jobs\NotifyNewLead;
use App\Models\Lead;
use App\Support\LeadAttribution;
use App\Support\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Turns a website form submission (short demo form, quiz, chat, exit popup,
 * notify-me) into a lead in the existing `leads` table, then queues the
 * new-lead alert and runs the "new_lead" automation rules.
 */
class LeadCaptureService
{
    public const WIDGETS = ['form', 'quiz', 'chat', 'whatsapp', 'exit'];

    public function __construct(private AutomationService $automation) {}

    /** Indian mobile → "+91XXXXXXXXXX", or null when it is not one. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return preg_match('/^[6-9]\d{9}$/', $digits) ? '+91'.$digits : null;
    }

    /** Bots fill the hidden "website" field; real visitors never see it. */
    public static function isSpam(Request $request): bool
    {
        return trim((string) $request->input('website', '')) !== '';
    }

    /**
     * Validate and store. Name falls back to "Website visitor" for widgets that
     * only ask for a phone number (exit popup).
     *
     * @param  array<string, mixed>  $extra  extra lead columns (e.g. status)
     *
     * @throws ValidationException
     */
    public function capture(Request $request, string $defaultWidget = 'form', array $extra = []): Lead
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'product' => ['nullable', 'string', 'max:50'],
            'product_interest' => ['nullable', 'string', 'max:50'],
            'message' => ['nullable', 'string', 'max:5000'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:191'],
            'organization' => ['nullable', 'string', 'max:191'],
            'source_widget' => ['nullable', Rule::in(self::WIDGETS)],
            'page_url' => ['nullable', 'string', 'max:500'],
            'consent' => ['nullable', 'boolean'],
        ]);

        $phone = null;
        if (filled($data['phone'] ?? null)) {
            $phone = self::normalizePhone($data['phone']);
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Please enter a valid 10-digit Indian mobile number.']);
            }
        }
        if (! $phone && blank($data['email'] ?? null)) {
            throw ValidationException::withMessages(['phone' => 'Please enter your mobile number.']);
        }

        $widget = $data['source_widget'] ?? $defaultWidget;
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            if (! in_array($widget, ['exit', 'whatsapp', 'form'], true) || blank($phone)) {
                throw ValidationException::withMessages(['name' => 'Please enter your name.']);
            }
            $name = 'Website visitor';
        }

        $product = $data['product'] ?? $data['product_interest'] ?? 'general';
        if (! in_array($product, Site::leadKeys(), true)) {
            $product = 'general';
        }

        $pageUrl = $data['page_url'] ?? null;
        if ($pageUrl && ! preg_match('#^https?://#i', $pageUrl)) {
            $pageUrl = null;
        }

        $lead = Lead::create([
            'name' => strip_tags($name),
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'organization' => $data['organization'] ?? null,
            'city' => $data['city'] ?? null,
            'message' => isset($data['message']) ? strip_tags($data['message']) : null,
            'product_interest' => $product,
            'business_type' => $data['business_type'] ?? null,
            'size' => $data['size'] ?? null,
            'source' => mb_substr(strip_tags((string) ($request->input('source') ?: 'website')), 0, 191),
            'source_widget' => $widget,
            'page_url' => $pageUrl,
            'consent_at' => ! empty($data['consent']) ? now() : null,
            'status' => 'new',
            ...LeadAttribution::fromRequest($request),
            ...$extra,
        ]);

        NotifyNewLead::dispatchFor($lead);

        $automation = $this->automation;
        dispatch(fn () => $automation->run('new_lead', $lead->id))->afterResponse();

        return $lead;
    }
}
