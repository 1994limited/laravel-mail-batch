<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use NineteenNinetyFour\MailBatch\BatchResult;
use NineteenNinetyFour\MailBatch\Contracts\BatchTransport;
use NineteenNinetyFour\MailBatch\Exceptions\BatchListenerException;
use NineteenNinetyFour\MailBatch\PendingBatch;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Throwable;

/**
 * Sends captured messages, pacing the requests made to the transport.
 */
class BatchFlusher
{
    use Localizable;

    /**
     * Create a new batch flusher instance.
     */
    public function __construct(protected ?Dispatcher $events)
    {
        //
    }

    /**
     * Send the captured messages with the given transport.
     *
     * @param  array<int, CapturedMessage>  $messages
     *
     * @throws BatchListenerException
     */
    public function flush(PendingBatch $batch, TransportInterface $transport, array $messages): BatchResult
    {
        $sent = $failures = [];

        $requests = 0;

        $listenerException = null;

        foreach ($this->requests($batch, $transport, $messages) as $i => $indexes) {
            if ($i > 0 && $batch->getInterval() > 0) {
                Sleep::usleep($batch->getInterval() * 1000);
            }

            $chunk = array_intersect_key($messages, array_flip($indexes));

            // A retried request is sent with the same idempotency key as the first attempt,
            // so a provider that supports one won't deliver the messages a second time.
            $options = ['idempotency_key' => (string) Str::uuid()];

            try {
                $results = retry(
                    $batch->getAttempts(),
                    function () use ($transport, $chunk, $options, &$requests) {
                        return $this->send($transport, $chunk, $options, $requests);
                    },
                    $batch->getRetrySleep(),
                );
            } catch (Throwable $e) {
                foreach ($indexes as $index) {
                    $failures[$index] = $e;
                }

                continue;
            }

            // Every message in the request has been settled at this point, so the results are
            // recorded before any event is dispatched. A listener that throws doesn't stop the
            // rest of the batch from being sent, and is rethrown once the batch is done.
            $sentInRequest = [];

            foreach ($results as $index => $result) {
                if ($result instanceof Throwable) {
                    $failures[$index] = $result;
                } else {
                    $sent[$index] = $sentInRequest[$index] = new SentMessage($result);
                }
            }

            foreach ($sentInRequest as $index => $sentMessage) {
                try {
                    $this->withLocale($messages[$index]->locale, fn () => $this->events?->dispatch(
                        new MessageSent($sentMessage, $messages[$index]->data)
                    ));
                } catch (Throwable $e) {
                    $listenerException ??= $e;
                }
            }
        }

        ksort($sent);
        ksort($failures);

        $result = new BatchResult($sent, $failures, $requests);

        if ($listenerException) {
            throw new BatchListenerException($result, $listenerException);
        }

        return $result;
    }

    /**
     * Group the captured messages into the requests that will be made to the transport.
     *
     * Messages are kept in the order they were captured in. Messages the transport is not
     * able to batch are sent on their own, between the batches that surround them.
     *
     * @param  array<int, CapturedMessage>  $messages
     * @return array<int, array<int, int>>
     */
    protected function requests(PendingBatch $batch, TransportInterface $transport, array $messages): array
    {
        if (! $transport instanceof BatchTransport) {
            return array_map(fn ($index) => [$index], array_keys($messages));
        }

        $max = max(1, $transport->maxBatchSize());

        $size = min($batch->getSize() ?? $max, $max);

        $requests = $current = [];

        foreach ($messages as $index => $captured) {
            if (! $transport->canBatch($captured->message)) {
                if ($current !== []) {
                    $requests[] = $current;
                    $current = [];
                }

                $requests[] = [$index];

                continue;
            }

            $current[] = $index;

            if (count($current) === $size) {
                $requests[] = $current;
                $current = [];
            }
        }

        if ($current !== []) {
            $requests[] = $current;
        }

        return $requests;
    }

    /**
     * Send a single request to the transport.
     *
     * A request with one message is sent with the transport's own "send" method, so Symfony's
     * transport events and throttling apply to it as they would to any other message.
     *
     * @param  array<int, CapturedMessage>  $messages
     * @param  array{idempotency_key?: string}  $options
     * @return array<int, SymfonySentMessage|Throwable>
     */
    protected function send(TransportInterface $transport, array $messages, array $options, int &$requests): array
    {
        if (count($messages) === 1) {
            $index = array_key_first($messages);

            $requests++;

            return [$index => $transport->send($messages[$index]->message, $messages[$index]->envelope)
                ?? new TransportException('The message was not sent by the transport.')];
        }

        // Like the transport's own "send" method, each message is cloned so that changes made
        // by the transport, such as adding the provider's ID as a header, are kept on the copy
        // that is handed to the sent event and returned in the results.
        $pending = [];

        foreach ($messages as $index => $captured) {
            $pending[$index] = new SymfonySentMessage(clone $captured->message, clone $captured->envelope);
        }

        $requests++;

        /** @var BatchTransport $transport */
        $failures = $transport->sendBatch($pending, $options);

        return array_replace($pending, $failures);
    }
}
