<?php

namespace NineteenNinetyFour\MailBatch\Tests\Transport;

use Illuminate\Support\Facades\Mail;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\TestMailable;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use NineteenNinetyFour\MailBatch\Transport\PostmarkBatchTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

class PostmarkBatchTransportTest extends TestCase
{
    protected function sentMessage(string $to, string $subject = 'Hello'): SentMessage
    {
        $email = (new Email)->from('from@example.com')->to($to)->subject($subject)->html('<p>Hi</p>');

        return new SentMessage($email, Envelope::create($email));
    }

    public function test_the_transport_is_registered_as_postmark_batch(): void
    {
        $this->app['config']->set('mail.mailers.postmark-batch', [
            'transport' => 'postmark-batch',
            'token' => 'token',
            'message_stream_id' => 'broadcast',
        ]);

        $transport = Mail::mailer('postmark-batch')->getSymfonyTransport();

        $this->assertInstanceOf(PostmarkBatchTransport::class, $transport);
        $this->assertSame('postmark+api://api.postmarkapp.com?message_stream=broadcast', (string) $transport);
    }

    public function test_only_small_emails_without_attachments_can_be_batched(): void
    {
        $transport = new PostmarkBatchTransport('token');

        $this->assertSame(500, $transport->maxBatchSize());
        $this->assertTrue($transport->canBatch($this->sentMessage('a@example.com')->getOriginalMessage()));
        $this->assertFalse($transport->canBatch((new Email)->from('f@example.com')->to('a@example.com')->text('Hi')->attach('x', 'x.txt')));
        $this->assertFalse($transport->canBatch((new Email)->from('f@example.com')->to('a@example.com')->html(str_repeat('a', 100_001))));
    }

    public function test_emails_are_sent_in_one_request_with_their_payloads(): void
    {
        $request = null;

        $client = new MockHttpClient(function ($method, $url, $options) use (&$request) {
            $request = compact('method', 'url', 'options');

            return new JsonMockResponse([
                ['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'id-1'],
                ['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'id-2'],
            ]);
        });

        $first = $this->sentMessage('first@example.com', 'First');
        $first->getOriginalMessage()->getHeaders()->add(new TagHeader('digest'));
        $first->getOriginalMessage()->getHeaders()->add(new MetadataHeader('user', '1'));

        $messages = [3 => $first, 7 => $this->sentMessage('second@example.com', 'Second')];

        $transport = (new PostmarkBatchTransport('token', $client))->setMessageStream('broadcast');

        $this->assertSame([], $transport->sendBatch($messages));

        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.postmarkapp.com/email/batch', $request['url']);
        $this->assertContains('X-Postmark-Server-Token: token', $request['options']['headers']);

        $payload = json_decode($request['options']['body'], true);

        $this->assertCount(2, $payload);
        $this->assertSame('first@example.com', $payload[0]['To']);
        $this->assertSame('digest', $payload[0]['Tag']);
        $this->assertSame(['user' => '1'], $payload[0]['Metadata']);
        $this->assertSame('broadcast', $payload[0]['MessageStream']);
        $this->assertSame('Second', $payload[1]['Subject']);

        $this->assertSame('id-1', $messages[3]->getMessageId());
        $this->assertSame('id-2', $messages[7]->getMessageId());
    }

    public function test_rejected_emails_are_reported_as_failures(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            ['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'id-1'],
            ['ErrorCode' => 300, 'Message' => 'Invalid email request'],
        ]));

        $messages = [$this->sentMessage('first@example.com'), $this->sentMessage('second@example.com')];

        $failures = (new PostmarkBatchTransport('token', $client))->sendBatch($messages);

        $this->assertSame([1], array_keys($failures));
        $this->assertSame('Postmark rejected the email: Invalid email request (code 300).', $failures[1]->getMessage());
        $this->assertSame('id-1', $messages[0]->getMessageId());
    }

    public function test_a_response_that_cannot_be_matched_fails_every_email(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            ['ErrorCode' => 0, 'Message' => 'OK', 'MessageID' => 'id-1'],
        ]));

        $failures = (new PostmarkBatchTransport('token', $client))->sendBatch([
            $this->sentMessage('first@example.com'), $this->sentMessage('second@example.com'),
        ]);

        $this->assertSame([0, 1], array_keys($failures));
        $this->assertStringContainsString('may or may not have been sent', $failures[0]->getMessage());
    }

    public function test_a_failed_request_throws(): void
    {
        $client = new MockHttpClient(new JsonMockResponse(
            ['ErrorCode' => 10, 'Message' => 'Bad or missing API token'], ['http_code' => 401],
        ));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send the emails: Bad or missing API token (code 10).');

        (new PostmarkBatchTransport('token', $client))->sendBatch([
            $this->sentMessage('first@example.com'), $this->sentMessage('second@example.com'),
        ]);
    }

    public function test_mail_batch_sends_through_postmark(): void
    {
        $requests = [];

        $client = new MockHttpClient(function ($method, $url, $options) use (&$requests) {
            $requests[] = $url;

            return str_ends_with($url, '/email/batch')
                ? new JsonMockResponse([
                    ['ErrorCode' => 0, 'MessageID' => 'id-1'],
                    ['ErrorCode' => 0, 'MessageID' => 'id-2'],
                ])
                : new MockResponse(json_encode(['ErrorCode' => 0, 'MessageID' => 'id-3']));
        });

        Mail::extend('postmark-mock', fn () => new PostmarkBatchTransport('token', $client));
        $this->app['config']->set('mail.mailers.postmark-mock', ['transport' => 'postmark-mock']);

        $result = Mail::mailer('postmark-mock')->batch()->size(2)->send([
            new TestMailable('taylor@laravel.com'),
            new TestMailable('dries@laravel.com'),
            new TestMailable('james@laravel.com'),
        ]);

        $this->assertTrue($result->successful());
        $this->assertSame([
            'https://api.postmarkapp.com/email/batch',
            'https://api.postmarkapp.com/email',
        ], $requests);
        $this->assertSame(['id-1', 'id-2', 'id-3'], array_map(fn ($sent) => $sent->getMessageId(), $result->sent()));
    }
}
