<?php

namespace App\Mail;

use App\Models\Reservation;
use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistPromoted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly WaitlistEntry $entry,
        public readonly Reservation $reservation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'SlotLock: se liberó el horario que estabas esperando',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.waitlist-promoted');
    }
}
