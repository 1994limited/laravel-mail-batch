<?php

namespace NineteenNinetyFour\MailBatch\Transport;

use NineteenNinetyFour\MailBatch\Contracts\BatchTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\Postmark\Event\PostmarkDeliveryEvent;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\MessageStreamHeader;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Symfony's Postmark API transport, able to send up to 500 emails in a single request.
 *
 * Single emails are sent by Symfony's transport itself. Batches are sent to Postmark's
 * "/email/batch" endpoint, which reports the result of each email separately.
 *
 * The payload is built the way Symfony's transport builds it, which is distributed under
 * the MIT license, copyright (c) Fabien Potencier.
 */
class PostmarkBatchTransport extends PostmarkApiTransport implements BatchTransport
{
    /**
     * The maximum size of an email's payload for it to be sent in a batch, in bytes.
     *
     * Postmark accepts up to 50 MB per batch request. Keeping every email in a batch to
     * 100 KB means a full batch of 500 emails always fits in a single request.
     */
    public const MAX_BATCHED_EMAIL_SIZE = 100_000;

    /**
     * The error code Postmark returns for an inactive recipient.
     */
    protected const CODE_INACTIVE_RECIPIENT = 406;

    /**
     * The message stream to send emails through.
     */
    protected ?string $stream = null;

    /**
     * Create a new Postmark batch transport instance.
     */
    public function __construct(
        #[\SensitiveParameter] protected string $token,
        ?HttpClientInterface $client = null,
        protected ?EventDispatcherInterface $events = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($token, $client, $events, $logger);
    }

    /**
     * Set the message stream to send emails through.
     *
     * @return $this
     */
    public function setMessageStream(string $messageStream): static
    {
        $this->stream = $messageStream;

        return parent::setMessageStream($messageStream);
    }

    /**
     * {@inheritDoc}
     */
    public function maxBatchSize(): int
    {
        return 500;
    }

    /**
     * {@inheritDoc}
     *
     * Emails with attachments, and large emails, are sent on their own so that a batch never
     * exceeds the size of request Postmark accepts.
     */
    public function canBatch(Email $email): bool
    {
        if ($email->getAttachments() !== []) {
            return false;
        }

        try {
            $payload = json_encode($this->toPayload($email, Envelope::create($email)), JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        return strlen($payload) <= static::MAX_BATCHED_EMAIL_SIZE;
    }

    /**
     * {@inheritDoc}
     *
     * Postmark doesn't support idempotency keys, so the "idempotency_key" option is ignored.
     *
     * @throws TransportException
     */
    public function sendBatch(array $messages, array $options = []): array
    {
        $emails = [];

        foreach ($messages as $index => $message) {
            $emails[$index] = MessageConverter::toEmail($message->getOriginalMessage());
        }

        $payloads = [];

        foreach ($messages as $index => $message) {
            $payloads[] = $this->toPayload($emails[$index], $message->getEnvelope());
        }

        $response = $this->client->request('POST', 'https://'.$this->endpoint().'/email/batch', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Postmark-Server-Token' => $this->token,
            ],
            'json' => $payloads,
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $results = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send the emails: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Postmark server.', $response, 0, $e);
        }

        if ($statusCode !== 200) {
            throw new HttpTransportException(sprintf(
                'Unable to send the emails: %s (code %d).',
                $results['Message'] ?? $results['message'] ?? 'HTTP '.$statusCode,
                $results['ErrorCode'] ?? 0,
            ), $response);
        }

        return $this->mapBatchResponse($messages, $emails, $results);
    }

    /**
     * Map Postmark's batch response onto the given messages, returning the failures.
     *
     * Postmark returns one result per email, in the order the emails were sent. A response
     * that can't be matched up fails every message, since we can't tell which were sent.
     *
     * @param  array<int, SentMessage>  $messages
     * @param  array<int, Email>  $emails
     * @return array<int, Throwable>
     */
    protected function mapBatchResponse(array $messages, array $emails, array $results): array
    {
        $results = array_values($results);

        if (! array_is_list($results) || count($results) !== count($messages)) {
            return array_map(fn () => new TransportException(
                'Unable to match the Postmark API response to the email. It may or may not have been sent.'
            ), $messages);
        }

        $failures = [];

        foreach (array_keys($messages) as $position => $index) {
            $result = $results[$position];

            $errorCode = (int) ($result['ErrorCode'] ?? -1);

            if ($errorCode === 0 && is_string($result['MessageID'] ?? null) && $result['MessageID'] !== '') {
                $messages[$index]->setMessageId($result['MessageID']);

                continue;
            }

            $errorMessage = $result['Message'] ?? 'Unknown error';

            // Like Symfony's transport, an inactive recipient is reported through the event
            // dispatcher, when there is one, instead of failing the email.
            if ($this->events !== null && $errorCode === static::CODE_INACTIVE_RECIPIENT) {
                $this->events->dispatch(new PostmarkDeliveryEvent($errorMessage, $errorCode, $emails[$index]->getHeaders()));

                continue;
            }

            $failures[$index] = new TransportException(
                sprintf('Postmark rejected the email: %s (code %d).', $errorMessage, $errorCode)
            );
        }

        return $failures;
    }

    /**
     * Build the Postmark API payload for the given email, as Symfony's transport builds it.
     *
     * @throws TransportException
     */
    protected function toPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'From' => $envelope->getSender()->toString(),
            'To' => implode(',', $this->stringifyAddresses($this->recipients($email, $envelope))),
            'Cc' => implode(',', $this->stringifyAddresses($email->getCc())),
            'Bcc' => implode(',', $this->stringifyAddresses($email->getBcc())),
            'ReplyTo' => implode(',', $this->stringifyAddresses($email->getReplyTo())),
            'Subject' => $email->getSubject(),
            'TextBody' => $email->getTextBody(),
            'HtmlBody' => $email->getHtmlBody(),
            'Attachments' => [],
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, ['from', 'to', 'cc', 'bcc', 'subject', 'content-type', 'sender', 'reply-to', 'date'], true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                if (isset($payload['Tag'])) {
                    throw new TransportException('Postmark only allows a single tag per email.');
                }

                $payload['Tag'] = $header->getValue();

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['Metadata'][$header->getKey()] = $header->getValue();

                continue;
            }

            if ($header instanceof MessageStreamHeader) {
                $payload['MessageStream'] = $header->getValue();

                continue;
            }

            $payload['Headers'][] = [
                'Name' => $header->getName(),
                'Value' => $header->getBodyAsString(),
            ];
        }

        if ($this->stream !== null && ! isset($payload['MessageStream'])) {
            $payload['MessageStream'] = $this->stream;
        }

        return $payload;
    }

    /**
     * Get the recipients without CC or BCC.
     *
     * @return array<int, Address>
     */
    protected function recipients(Email $email, Envelope $envelope): array
    {
        return array_filter($envelope->getRecipients(), fn (Address $address) => ! in_array(
            $address, array_merge($email->getCc(), $email->getBcc()), true,
        ));
    }

    /**
     * Get the host and port of the Postmark API.
     */
    protected function endpoint(): string
    {
        return ($this->host ?: 'api.postmarkapp.com').($this->port ? ':'.$this->port : '');
    }
}
