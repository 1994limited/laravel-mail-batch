<?php

namespace NineteenNinetyFour\MailBatch\Tests\Transport;

use Illuminate\Support\Facades\Mail;
use Mockery;
use NineteenNinetyFour\MailBatch\Tests\TestCase;
use NineteenNinetyFour\MailBatch\Transport\ResendBatchTransport;
use Resend\Collection;
use Resend\Contracts\Client;
use Resend\Email as ResendEmail;
use Resend\Service\Batch as BatchService;
use Resend\Service\Email as EmailService;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

class ResendBatchTransportTest extends TestCase
{
    public function test_the_transport_is_registered_as_resend_batch(): void
    {
        $this->app['config']->set('mail.mailers.resend-batch', ['transport' => 'resend-batch', 'key' => 'foo']);

        $this->assertInstanceOf(ResendBatchTransport::class, Mail::mailer('resend-batch')->getSymfonyTransport());
    }

    public function test_can_batch_emails_without_attachments(): void
    {
        $transport = new ResendBatchTransport(Mockery::mock(Client::class));

        $this->assertSame(100, $transport->maxBatchSize());
        $this->assertTrue($transport->canBatch((new Email)->text('Hello')));
        $this->assertFalse($transport->canBatch((new Email)->text('Hello')->attach('content', 'file.txt')));
    }

    public function test_send_batch(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')
            ->withArgs(function (array $payloads, array $options) {
                return count($payloads) === 2
                    && $payloads[0]['to'] === ['first@example.com']
                    && $payloads[1]['subject'] === 'Second'
                    && ! array_key_exists('attachments', $payloads[0])
                    && $options === ['batch_validation' => 'permissive', 'idempotency_key' => 'key-1'];
            })
            ->andReturn(Collection::from([
                'data' => [
                    ResendEmail::from(['id' => 'resend_id_1']),
                    ResendEmail::from(['id' => 'resend_id_2']),
                ],
            ]));

        $messages = [5 => $this->sentMessage('first@example.com', 'First'), 9 => $this->sentMessage('second@example.com', 'Second')];

        $failures = (new ResendBatchTransport($client))->sendBatch($messages, ['idempotency_key' => 'key-1']);

        $this->assertSame([], $failures);
        $this->assertSame('resend_id_1', $messages[5]->getMessageId());
        $this->assertSame('resend_id_2', $messages[9]->getMessageId());
        $this->assertSame(
            'resend_id_2',
            $messages[9]->getOriginalMessage()->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString()
        );
    }

    public function test_send_batch_maps_permissive_validation_errors_to_their_messages(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')->andReturn(Collection::from([
            'data' => [
                ResendEmail::from(['id' => 'resend_id_1']),
                ResendEmail::from(['id' => 'resend_id_3']),
            ],
            'errors' => [
                ['index' => 1, 'message' => 'Invalid `to` field'],
            ],
        ]));

        $messages = [
            0 => $this->sentMessage('first@example.com', 'First'),
            1 => $this->sentMessage('second@example.com', 'Second'),
            2 => $this->sentMessage('third@example.com', 'Third'),
        ];

        $failures = (new ResendBatchTransport($client))->sendBatch($messages);

        $this->assertSame([1], array_keys($failures));
        $this->assertInstanceOf(TransportException::class, $failures[1]);
        $this->assertStringContainsString('Invalid `to` field', $failures[1]->getMessage());
        $this->assertSame('resend_id_1', $messages[0]->getMessageId());
        $this->assertSame('resend_id_3', $messages[2]->getMessageId());
    }

    public function test_send_batch_uses_the_configured_validation_mode(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')
            ->withArgs(fn ($payloads, $options) => $options === ['batch_validation' => 'strict'])
            ->andThrow(new \Exception('Validation failed'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Request to Resend API failed. Reason: Validation failed.');

        (new ResendBatchTransport($client, 'strict'))->sendBatch([
            $this->sentMessage('first@example.com', 'First'),
            $this->sentMessage('second@example.com', 'Second'),
        ]);
    }

    public function test_send_batch_fails_every_accepted_message_when_the_response_cannot_be_matched(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')->andReturn(Collection::from([
            'data' => [ResendEmail::from(['id' => 'resend_id_1'])],
            'errors' => [['index' => 2, 'message' => 'Invalid `to` field']],
        ]));

        $failures = (new ResendBatchTransport($client))->sendBatch([
            $this->sentMessage('first@example.com', 'First'),
            $this->sentMessage('second@example.com', 'Second'),
            $this->sentMessage('third@example.com', 'Third'),
        ]);

        $this->assertSame([0, 1, 2], array_keys($failures));
        $this->assertStringContainsString('Invalid `to` field', $failures[2]->getMessage());
        $this->assertStringContainsString('Unable to match', $failures[0]->getMessage());
    }

    public function test_send_batch_fails_every_message_when_an_error_index_is_invalid(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')->andReturn(Collection::from([
            'data' => [ResendEmail::from(['id' => 'resend_id_1'])],
            'errors' => [['index' => 7, 'message' => 'Invalid']],
        ]));

        $failures = (new ResendBatchTransport($client))->sendBatch([
            $this->sentMessage('first@example.com', 'First'),
            $this->sentMessage('second@example.com', 'Second'),
        ]);

        $this->assertSame([0, 1], array_keys($failures));
    }

    public function test_send_batch_fails_messages_without_an_id(): void
    {
        $client = Mockery::mock(Client::class);
        $batchService = Mockery::mock(BatchService::class);
        $client->batch = $batchService;

        $batchService->expects('send')->andReturn(Collection::from([
            'data' => [ResendEmail::from(['id' => 'resend_id_1']), ResendEmail::from([])],
        ]));

        $messages = [
            $this->sentMessage('first@example.com', 'First'),
            $this->sentMessage('second@example.com', 'Second'),
        ];

        $failures = (new ResendBatchTransport($client))->sendBatch($messages);

        $this->assertSame([1], array_keys($failures));
        $this->assertSame('resend_id_1', $messages[0]->getMessageId());
    }

    public function test_stale_resend_id_headers_are_not_sent_and_are_replaced(): void
    {
        $email = (new Email)->from('myself@example.com')->to('me@example.com')->subject('Foo')->text('Bar');
        $email->getHeaders()->addTextHeader('X-Resend-Email-ID', 'stale_id');

        $client = Mockery::mock(Client::class);
        $emailService = Mockery::mock(EmailService::class);
        $client->emails = $emailService;

        $emailService->expects('send')
            ->withArgs(fn ($payload) => ! array_key_exists('X-Resend-Email-ID', $payload['headers']))
            ->andReturn(ResendEmail::from(['id' => 'fresh_id']));

        $sentMessage = (new ResendBatchTransport($client))->send($email);

        $headers = $sentMessage->getOriginalMessage()->getHeaders()->all('X-Resend-Email-ID');

        $this->assertSame(['fresh_id'], array_map(fn ($header) => $header->getBodyAsString(), iterator_to_array($headers, false)));
    }

    protected function sentMessage(string $to, string $subject): SentMessage
    {
        $email = (new Email)->from('myself@example.com')->to($to)->subject($subject)->text('Body');

        return new SentMessage($email, Envelope::create($email));
    }
}
