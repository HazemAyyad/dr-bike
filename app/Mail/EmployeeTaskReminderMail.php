<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeTaskReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $employeeName,
        public string $taskName,
        public string $bodyLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('messages.employee_task_reminder_email_subject', ['name' => $this->taskName]),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'.e(__('messages.employee_task_reminder_email_greeting', ['name' => $this->employeeName])).'</p>'
                .'<p>'.e($this->bodyLine).'</p>'
                .'<p>'.e(__('messages.employee_task_reminder_email_footer')).'</p>',
        );
    }
}
