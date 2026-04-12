<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $get_user_email;


    public $validToken;

    /**
     * Create a new message instance.
     */
    public function __construct($get_user_email,$validToken)
    {
        $this->get_user_email = $get_user_email;
        $this->validToken = $validToken;



    }

    public function build()
    {
        return $this->view('emails.resetPassword')->with([
            'user_email' => $this->get_user_email,
            'validToken' => $this->validToken,


        ]);
    }
}
