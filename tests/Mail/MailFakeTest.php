<?php

namespace NineteenNinetyFour\MailBatch\Tests\Mail;

use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\MailBatch\Facades\MailBatch;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\TestMailable;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class MailFakeTest extends TestCase
{
    public function test_nothing_reaches_the_transport_when_mail_is_faked(): void
    {
        Mail::fake();

        Mail::batch([new TestMailable('taylor@laravel.com'), new TestMailable('dries@laravel.com')]);
        Mail::mailer('batched')->batch([new TestMailable('james@laravel.com')]);
        MailBatch::capture(fn ($mailer) => $mailer->send(new TestMailable('nuno@laravel.com')));
        Mail::batch()->capture(fn () => Mail::send(new TestMailable('jess@laravel.com')));

        $this->assertSame([], $this->transport->batches);
        $this->assertSame([], $this->transport->singles);

        Mail::assertSent(TestMailable::class, 5);
    }

    public function test_batches_can_be_asserted(): void
    {
        Mail::fake();

        MailBatch::assertNotBatched();

        Mail::mailer('resend')->batch([new TestMailable, new TestMailable]);
        Mail::batch()->capture(function () {
            Mail::send(new TestMailable);
            Mail::mailer('array')->send(new TestMailable);
        });

        MailBatch::assertBatchCount(2);
        MailBatch::assertBatched(fn ($mailables, $mailer) => $mailables->count() === 2 && $mailer === 'resend');
        MailBatch::assertBatched(fn ($mailables, $mailer) => $mailables->count() === 1 && $mailer === 'batched');

        $this->expectException(ExpectationFailedException::class);

        MailBatch::assertBatched(fn ($mailables) => $mailables->count() === 3);
    }
}
