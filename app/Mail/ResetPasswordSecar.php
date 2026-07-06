<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordSecar extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
        public string $nombre
    ) {}

    public function build()
    {
        return $this->subject('Restablece tu contraseña — Secar Ingenieros')
                    ->view('emails.reset-password');
    }
}