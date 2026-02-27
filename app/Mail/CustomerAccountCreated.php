<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CustomerAccountCreated extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $password
    ) {}

    public function build(): self
    {
        return $this->from('no-replay@proteng.com', config('app.name'))
            ->subject('Your account has been created')
            ->view('emails.customer-account-created', [
                'user' => $this->user,
                'password' => $this->password,
            ]);
    }
}
