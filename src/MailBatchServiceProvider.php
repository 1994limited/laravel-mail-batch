<?php

namespace NineteenNinetyFour\MailBatch;

use Closure;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use NineteenNinetyFour\MailBatch\Capture\MessageMetadata;
use NineteenNinetyFour\MailBatch\Notifications\NotificationBatcher;
use NineteenNinetyFour\MailBatch\Testing\BatchRecorder;
use NineteenNinetyFour\MailBatch\Transport\PostmarkBatchTransport;
use NineteenNinetyFour\MailBatch\Transport\ResendBatchTransport;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;

/**
 * Registers the batch APIs without replacing any of Laravel's own services.
 *
 * Mail::batch() and Notification::batch() are macros, and mail is captured by wrapping
 * the transport of a mailer only while a batch is open on it, so packages that extend
 * the mail manager, the notification channel manager or their drivers keep working.
 */
class MailBatchServiceProvider extends ServiceProvider
{
    /**
     * Register the package's services.
     */
    public function register(): void
    {
        $this->app->singleton(BatchRecorder::class);
        $this->app->singleton(MailBatcher::class);
        $this->app->singleton(NotificationBatcher::class);
    }

    /**
     * Bootstrap the package's services.
     */
    public function boot(): void
    {
        $this->app['events']->listen(MessageSending::class, [MessageMetadata::class, 'record']);

        Mailer::macro('batch', function ($mailables = null) {
            /** @var Mailer $this */
            $batch = app(MailBatcher::class)->pending($this, $this->name);

            return is_null($mailables) ? $batch : $batch->send($mailables);
        });

        ChannelManager::macro('batch', function ($notifiables, $notification) {
            /** @var ChannelManager $this */
            [$locale, $this->locale] = [$this->locale, null];

            app(NotificationBatcher::class)->send($this, $notifiables, $notification, $locale);
        });

        ChannelManager::macro('batchNow', function ($notifiables, $notification, ?array $channels = null) {
            /** @var ChannelManager $this */
            [$locale, $this->locale] = [$this->locale, null];

            app(NotificationBatcher::class)->sendNow($this, $notifiables, $notification, $channels, [], $locale);
        });

        // A faked notification is recorded as sent, the way Notification::send() records it.
        NotificationFake::macro('batch', function ($notifiables, $notification) {
            /** @var NotificationFake $this */
            $this->send($notifiables, $notification);
        });

        NotificationFake::macro('batchNow', function ($notifiables, $notification, ?array $channels = null) {
            /** @var NotificationFake $this */
            $this->sendNow($notifiables, $notification, $channels);
        });

        $this->registerResendTransport();
        $this->registerPostmarkTransport();
    }

    /**
     * Register the "resend-batch" mail transport when the Resend SDK is installed.
     */
    protected function registerResendTransport(): void
    {
        if (! class_exists(\Resend::class)) {
            return;
        }

        $this->extendMailManager('resend-batch', fn (array $config) => new ResendBatchTransport(
            \Resend::client($config['key'] ?? $this->app['config']->get('services.resend.key')),
            $config['batch_validation'] ?? 'permissive',
        ));
    }

    /**
     * Register the "postmark-batch" mail transport when Symfony's Postmark mailer is installed.
     */
    protected function registerPostmarkTransport(): void
    {
        if (! class_exists(PostmarkApiTransport::class)) {
            return;
        }

        $this->extendMailManager('postmark-batch', function (array $config) {
            $transport = new PostmarkBatchTransport(
                $config['token'] ?? $config['key']
                    ?? $this->app['config']->get('services.postmark.token')
                    ?? $this->app['config']->get('services.postmark.key'),
                isset($config['client']) ? HttpClient::create($config['client']) : null,
            );

            if (isset($config['message_stream_id'])) {
                $transport->setMessageStream($config['message_stream_id']);
            }

            return $transport;
        });
    }

    /**
     * Register a mail transport, whether or not the mail manager has been resolved yet.
     */
    protected function extendMailManager(string $transport, Closure $callback): void
    {
        $register = function ($manager) use ($transport, $callback) {
            if (method_exists($manager, 'extend')) {
                $manager->extend($transport, $callback);
            }
        };

        if ($this->app->resolved('mail.manager')) {
            $register($this->app['mail.manager']);
        } else {
            $this->app->afterResolving('mail.manager', $register);
        }
    }
}
