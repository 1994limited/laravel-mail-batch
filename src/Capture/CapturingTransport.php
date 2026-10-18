<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use Illuminate\Support\Traits\ForwardsCalls;
use LogicException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps a mailer's transport while a batch is open on the mailer.
 *
 * Messages sent by the coroutine or fiber that opened the batch are captured instead of
 * sent. Everything else is handed straight to the wrapped transport, which is put back
 * on the mailer once the last batch open on it, in any coroutine or fiber, is closed.
 */
class CapturingTransport implements TransportInterface
{
    use ForwardsCalls;

    /**
     * The number of captures open on any transport.
     */
    protected static int $open = 0;

    /**
     * The captures open on this transport, keyed by context.
     *
     * @var array<string, Capture>
     */
    protected array $captures = [];

    /**
     * Create a new capturing transport instance.
     */
    public function __construct(protected TransportInterface $transport)
    {
        //
    }

    /**
     * Determine if a capture is open on any transport.
     */
    public static function capturing(): bool
    {
        return static::$open > 0;
    }

    /**
     * Open a capture for the current context.
     *
     * @throws LogicException
     */
    public function open(): Capture
    {
        $context = Context::key();

        if (isset($this->captures[$context])) {
            throw new LogicException('A batch is already in progress on this mailer.');
        }

        static::$open++;

        return $this->captures[$context] = new Capture;
    }

    /**
     * Close the given capture.
     */
    public function close(Capture $capture): void
    {
        foreach ($this->captures as $context => $open) {
            if ($open === $capture) {
                unset($this->captures[$context]);

                static::$open--;
            }
        }
    }

    /**
     * Get the capture open for the current context, if any.
     */
    public function current(): ?Capture
    {
        return $this->captures[Context::key()] ?? null;
    }

    /**
     * Determine if any capture is open on this transport.
     */
    public function hasOpenCaptures(): bool
    {
        return $this->captures !== [];
    }

    /**
     * Get the wrapped transport.
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Capture the message, or send it with the wrapped transport when no capture is open.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $capture = $this->current();

        if (is_null($capture) || ! $message instanceof Email) {
            return $this->transport->send($message, $envelope);
        }

        [$data, $locale] = MessageMetadata::pull($message);

        $capture->add(new CapturedMessage(
            $message, $envelope ?? Envelope::create($message), $data, $locale,
        ));

        // Returning nothing tells the mailer the message wasn't sent, so it doesn't dispatch
        // the sent event. The batch dispatches it once the message has actually been sent.
        return null;
    }

    /**
     * Get the string representation of the wrapped transport.
     */
    public function __toString(): string
    {
        return (string) $this->transport;
    }

    /**
     * Pass calls for methods of the wrapped transport through to it.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->transport, $method, $parameters);
    }
}
