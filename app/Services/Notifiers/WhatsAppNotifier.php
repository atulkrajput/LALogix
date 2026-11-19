<?php

namespace App\Services\Notifiers;

use App\Models\Lead;

/**
 * Sends the owner a WhatsApp message about a new lead.
 *
 * Interface only for now: bind a real implementation (WhatsApp Cloud API,
 * Interakt, AiSensy, ...) in AppServiceProvider when one is chosen.
 */
interface WhatsAppNotifier
{
    public function notifyNewLead(Lead $lead, string $summary): void;
}
