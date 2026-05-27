<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Weekly digest emailed to admins every Monday at 08:00.
 *
 * Replaces "did anyone log in to look at the dashboard this week?" with a
 * proactive single-page summary covering the four KPIs NAF operators care
 * about week-to-week:
 *
 *   - Documents added this week
 *   - Pending disinfestation (>30 days waiting)
 *   - Boxes still IN that should be PERM_OUT (stale movements)
 *   - Open document flags by severity
 *
 * RFQ-2026-06 enhancement (value-add beyond §3.2 — proactive reporting).
 */
class WeeklyOperationsDigest extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $stats
     */
    public function __construct(public array $stats) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Batch List Tool — Weekly Operations Digest ' . now()->format('Y-m-d'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly-operations-digest',
            with: ['stats' => $this->stats],
        );
    }
}
