<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AutomationRule;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\WhatsappLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the enabled automation rules for a trigger (e.g. `new_lead`).
 * Ported from old/src/lib/automation.ts.
 */
class AutomationService
{
    public function __construct(private EmailService $email) {}

    public function run(string $trigger, int $leadId): void
    {
        $lead = Lead::find($leadId);
        if (! $lead) {
            return;
        }

        $rules = AutomationRule::where('trigger', $trigger)->where('enabled', true)->get();

        foreach ($rules as $rule) {
            try {
                match ($rule->action) {
                    'send_email' => $this->sendEmail($rule, $lead),
                    'mark_followup' => $this->markFollowup($rule, $lead),
                    'send_whatsapp' => $this->sendWhatsapp($rule, $lead),
                    default => null,
                };
            } catch (Throwable $e) {
                Log::error("[Automation] Rule \"{$rule->name}\" failed for lead {$leadId}: ".$e->getMessage());
            }
        }
    }

    private function sendEmail(AutomationRule $rule, Lead $lead): void
    {
        $subject = "Follow-up: {$lead->name}";
        $body = '<p>Hi '.e($lead->name).',</p><p>Thank you for your interest in LALogix.</p>';

        if ($rule->template_id && ($template = EmailTemplate::find($rule->template_id))) {
            $subject = EmailService::personalize($template->subject, $lead->name, null);
            $body = EmailService::personalize($template->body, $lead->name, $lead->organization);
        }

        $this->email->send($lead->email, $subject, $body);

        Activity::create([
            'lead_id' => $lead->id,
            'type' => 'email',
            'notes' => "[Auto] {$rule->name}: {$subject}",
        ]);
    }

    private function markFollowup(AutomationRule $rule, Lead $lead): void
    {
        $lead->update([
            'next_followup_date' => now()->addDay(),
            'followup_status' => 'pending',
        ]);

        Activity::create([
            'lead_id' => $lead->id,
            'type' => 'meeting',
            'notes' => "[Auto] {$rule->name}: Follow-up scheduled",
        ]);
    }

    private function sendWhatsapp(AutomationRule $rule, Lead $lead): void
    {
        WhatsappLog::create([
            'lead_id' => $lead->id,
            'message' => "Hi {$lead->name}, thank you for your interest in LALogix. We'd love to schedule a demo for you.",
            'status' => 'sent',
        ]);

        Activity::create([
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
            'notes' => "[Auto] {$rule->name}: WhatsApp sent",
        ]);
    }
}
