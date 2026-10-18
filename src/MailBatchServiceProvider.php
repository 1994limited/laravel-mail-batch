<?php

namespace NineteenNinetyFour\MailBatch;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Mailer;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use NineteenNinetyFour\MailBatch\Capture\MessageMetadata;
use NineteenNinetyFour\MailBatch\Notifications\NotificationBatcher;
use NineteenNinetyFour\MailBatch\Testing\BatchRecorder;
use NineteenNinetyFour\MailBatch\Transport\ResendBatchTransport;

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
    }

    /**
     * Register the "resend-batch" mail transport when the Resend SDK is installed.
     */
    protected function registerResendTransport(): void
    {
        if (! class_exists(\Resend::class)) {
            return;
        }

        $register = function ($manager) {
            if (! method_exists($manager, 'extend')) {
                return;
            }

            $manager->extend('resend-batch', fn (array $config) => new ResendBatchTransport(
                \Resend::client($config['key'] ?? $this->app['config']->get('services.resend.key')),
                $config['batch_validation'] ?? 'permissive',
            ));
        };

        if ($this->app->resolved('mail.manager')) {
            $register($this->app['mail.manager']);
        } else {
            $this->app->afterResolving('mail.manager', $register);
        }
    }
}
