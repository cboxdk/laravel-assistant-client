<?php

declare(strict_types=1);

namespace Cbox\AiAssistant\Connector\Profile;

/**
 * The fixed vocabulary of account health flags. Assistant uses them for triage and for the
 * staff customer card, so a flag only means something when every service uses the same word.
 */
enum SignalFlag: string
{
    case PaymentFailed = 'payment_failed';
    case InvoiceOverdue = 'invoice_overdue';
    case IncidentOpen = 'incident_open';
    case SiteDown = 'site_down';
    case CertificateExpiring = 'certificate_expiring';
    case DeployFailed = 'deploy_failed';
    case LimitReached = 'limit_reached';
    case TrialEnding = 'trial_ending';
}
