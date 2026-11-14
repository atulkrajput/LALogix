<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends e-mail through the Resend API when RESEND_API_KEY is set,
 * otherwise logs the message and reports success (development stub).
 * Ported from old/src/lib/email.ts.
 */
class EmailService
{
    /**
     * @param  list<array{filename: string, content: string}>  $attachments  content is base64
     * @return array{success: bool, messageId?: string, error?: string}
     */
    public function send(string $to, string $subject, ?string $html = null, ?string $text = null, array $attachments = []): array
    {
        Log::info('[Email] Sending email', [
            'to' => $to,
            'subject' => $subject,
            'bodyLength' => strlen($html ?? $text ?? ''),
            'attachments' => array_column($attachments, 'filename'),
        ]);

        $apiKey = config('lalogix.resend_api_key');

        if (! $apiKey) {
            return ['success' => true, 'messageId' => 'stub-'.(int) (microtime(true) * 1000)];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->post('https://api.resend.com/emails', array_filter([
                    'from' => config('lalogix.email_from'),
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                    'text' => $text,
                    'attachments' => $attachments ?: null,
                ], fn ($v) => $v !== null));

            if (! $response->successful()) {
                Log::error('[Email] Resend API error', ['body' => $response->json()]);

                return ['success' => false, 'error' => 'Failed to send email'];
            }

            return ['success' => true, 'messageId' => (string) $response->json('id')];
        } catch (Throwable $e) {
            Log::error('[Email] Resend send error: '.$e->getMessage());

            return ['success' => false, 'error' => 'Email service unavailable'];
        }
    }

    /**
     * Notify the sales inbox. Values are HTML-escaped before going into the body.
     *
     * @param  array<string, string|null>  $fields  label => value
     */
    public function notifySales(string $subject, string $heading, array $fields): void
    {
        $rows = '';
        foreach ($fields as $label => $value) {
            $value = ($value === null || $value === '') ? 'N/A' : $value;
            $rows .= '<p><strong>'.e($label).':</strong> '.e($value).'</p>';
        }

        $this->send(config('lalogix.sales_email'), $subject, '<h2>'.e($heading).'</h2>'.$rows);
    }

    /** Replace the {{name}} and {{organization}} placeholders used by templates. */
    public static function personalize(string $text, string $name, ?string $organization): string
    {
        return str_replace(['{{name}}', '{{organization}}'], [$name, $organization ?? ''], $text);
    }
}
