<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\EmailService;
use App\Services\Notifiers\WhatsAppNotifier;
use App\Support\Seo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the owner about a new website lead: e-mail to LEAD_ALERT_EMAIL
 * (info@lalogix.com) now, WhatsApp later through the WhatsAppNotifier interface.
 */
class NotifyNewLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Lead $lead) {}

    /**
     * Queue it. With the "sync" queue (shared hosting without a worker) it runs
     * right after the response is sent, so the visitor never waits for the e-mail.
     */
    public static function dispatchFor(Lead $lead): void
    {
        if (config('queue.default') === 'sync') {
            static::dispatchAfterResponse($lead);
        } else {
            static::dispatch($lead);
        }
    }

    public function handle(EmailService $email, WhatsAppNotifier $whatsapp): void
    {
        $l = $this->lead;
        $fields = [
            'Name' => $l->name,
            'Phone' => $l->phone,
            'E-mail' => $l->email,
            'Product' => $l->product_interest,
            'From' => $l->source_widget,
            'Business type' => $l->business_type,
            'Size' => $l->size,
            'City' => $l->city,
            'Message' => $l->message,
            'Page' => $l->page_url,
            'Campaign' => trim(implode(' / ', array_filter([$l->utm_source, $l->utm_medium, $l->utm_campaign])), ' /'),
            'Open in admin' => Seo::siteUrl()."/admin/leads/{$l->id}",
        ];

        $rows = '';
        foreach ($fields as $label => $value) {
            if ($value !== null && $value !== '') {
                $rows .= '<p><strong>'.e($label).':</strong> '.e((string) $value).'</p>';
            }
        }

        $subject = "New lead: {$l->name} ({$l->product_interest}, {$l->source_widget})";
        $email->send(config('lalogix.lead_alert_email'), $subject, '<h2>New website lead</h2>'.$rows);

        $whatsapp->notifyNewLead($l, "{$subject} {$l->phone}");
    }
}
