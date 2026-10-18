<?php

namespace NineteenNinetyFour\MailBatch\Tests\Fixtures;

use Illuminate\Mail\Mailable;

class TestMailable extends Mailable
{
    public function __construct(public string $recipient = 'taylor@laravel.com')
    {
        //
    }

    public function build()
    {
        return $this->to($this->recipient)->subject('Hello')->html('<p>Hello</p>');
    }
}
