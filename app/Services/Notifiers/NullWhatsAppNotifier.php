<?php

namespace App\Services\Notifiers;

use App\Models\Lead;

/** Default: does nothing until a WhatsApp provider is set up. */
class NullWhatsAppNotifier implements WhatsAppNotifier
{
    public function notifyNewLead(Lead $lead, string $summary): void
    {
        // TODO: send via a WhatsApp Business API provider.
    }
}
