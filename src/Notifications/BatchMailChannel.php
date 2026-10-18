<?php

namespace NineteenNinetyFour\MailBatch\Notifications;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Mailable as BaseMailable;
use Illuminate\Mail\SentMessage;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's mail channel, sending with the batch's mailer unless the message names its own.
 *
 * It's only used in place of the stock mail channel, and only while a batched notification
 * is sent. An application's own mail channel is always used as it is.
 */
class BatchMailChannel extends MailChannel
{
    /**
     * The mailer messages are sent with when they don't name a mailer.
     */
    protected ?string $defaultMailer = null;

    /**
     * Send messages that don't name a mailer with the given mailer.
     *
     * @return $this
     */
    public function withDefaultMailer(?string $mailer): static
    {
        $this->defaultMailer = $mailer;

        return $this;
    }

    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @return SentMessage|null
     */
    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toMail($notifiable);

        if (! $notifiable->routeNotificationFor('mail', $notification) &&
            ! $message instanceof Mailable) {
            return;
        }

        if ($message instanceof Mailable) {
            if (! is_null($this->defaultMailer) && $message instanceof BaseMailable && is_null($message->mailer)) {
                $message->mailer($this->defaultMailer);
            }

            return $message->send($this->mailer);
        }

        return $this->mailer->mailer($message->mailer ?? $this->defaultMailer)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }
}
