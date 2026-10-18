# Laravel Mail Batch

Send mail and notifications in batches. Each request to your mail provider carries as many messages as it allows, and requests are paced to stay under your rate limit.

- `Mail::batch()` sends many mailables in as few requests as possible and returns a result for each message.
- `Notification::batch()` sends a notification to many notifiables with its mail batched. The `NotificationSent` and `NotificationFailed` events still fire for each notifiable, carrying the provider's response.
- Queued notifications are grouped into one job per batch, and those jobs are spaced out so your queue workers stay close to the rate limit.
- It works with every mailer. Resend and Postmark get real batch requests. Any other transport, such as SMTP or SES, sends the messages one at a time, still paced.
- It doesn't replace any of Laravel's services, so packages that extend mail or notifications keep working.

Requires PHP 8.3+ and Laravel 13.

## Installation

```bash
composer require 1994/laravel-mail-batch
```

### Resend

To send real batches through Resend's batch API (up to 100 emails per request), install the Resend SDK and use the `resend-batch` transport:

```bash
composer require resend/resend-php
```

```php
// config/mail.php
'mailers' => [
    'resend' => [
        'transport' => 'resend-batch',
        'key' => env('RESEND_API_KEY'), // defaults to services.resend.key
        'batch_validation' => 'permissive', // or "strict"
        'batch' => ['size' => 100, 'per_second' => 2],
    ],
],
```

`resend-batch` sends single emails the way the `resend` transport does, so you can use it as your only Resend mailer. Emails with attachments are sent one at a time, because Resend's batch endpoint doesn't accept attachments.

`batch_validation` controls what Resend does when some emails in a batch request are invalid, such as an email with a malformed address:

| Value | What Resend does | Effect on the result |
|---|---|---|
| `permissive` (default) | Sends the valid emails and reports the invalid ones. | Only the invalid emails are listed in `$result->failures()`, and only their notifiables get `NotificationFailed`. |
| `strict` | Rejects the whole request if any email is invalid, so nothing in it is sent. | Every email in the request fails. |

`permissive` is the default because it gives a result for each message. Be careful with `strict` and queued notifications: one invalid address fails the whole batch, so the job is retried and fails the same way every time until it runs out of tries.

### Postmark

To send real batches through Postmark's batch API (up to 500 emails per request), install Symfony's Postmark mailer and HTTP client, then use the `postmark-batch` transport:

```bash
composer require symfony/postmark-mailer symfony/http-client
```

```php
// config/mail.php
'mailers' => [
    'postmark' => [
        'transport' => 'postmark-batch',
        'token' => env('POSTMARK_TOKEN'), // defaults to services.postmark.token
        'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
        'batch' => ['size' => 500, 'per_second' => 2],
    ],
],
```

`postmark-batch` is Symfony's Postmark transport, so single emails are sent exactly as the `postmark` transport sends them. Tags, metadata and message streams work the same way in batches.

Postmark accepts up to 50 MB per batch request. To keep every batch under that limit, emails with attachments and emails over 100 KB are sent one at a time. Postmark doesn't support idempotency keys, so a retried batch request can deliver an email twice.

## Sending mail in batches

```php
use Illuminate\Support\Facades\Mail;

$result = Mail::batch($mailables);

$result = Mail::mailer('resend')
    ->batch()
    ->size(50)          // messages per request, capped at the transport's maximum
    ->perSecond(2)      // or ->interval(500) in milliseconds
    ->retry(3, 1000)    // attempts, and the sleep between them in milliseconds
    ->send($mailables);
```

`capture()` batches the mail sent through the mailer inside the callback:

```php
$result = Mail::batch()->capture(function ($mailer) use ($users) {
    foreach ($users as $user) {
        $mailer->to($user)->send(new Digest($user));
    }
});
```

The `MailBatch` facade offers the same methods: `MailBatch::send()`, `MailBatch::capture()` and `MailBatch::mailer('resend')`.

### Results

```php
$result->sent();        // array<int, Illuminate\Mail\SentMessage>, keyed by capture order
$result->failures();    // array<int, Throwable>, keyed by capture order
$result->successful();  // true if every message was sent
$result->requests();    // number of requests made to the transport
$result->throw();       // throws BatchSendException if any message failed
```

### Defaults per mailer

```php
'resend' => [
    'transport' => 'resend-batch',
    'batch' => ['size' => 100, 'per_second' => 5, 'retry' => 2, 'retry_sleep' => 1000],
],
```

Options set fluently take precedence over the mailer's config. If neither sets an option:

| Option | Default |
|---|---|
| `size` | the transport's maximum, or 1 message per request when it has no batch API |
| interval between requests | none |
| attempts per request | 1 |

## Sending notifications in batches

```php
use Illuminate\Support\Facades\Notification;

Notification::batch($users, new WeeklySummary);     // queued if the notification implements ShouldQueue
Notification::batchNow($users, new WeeklySummary);  // sent now
```

Configure the batch on the notification:

```php
use NineteenNinetyFour\MailBatch\Attributes\BatchMail;

#[BatchMail(size: 100, perSecond: 2, mailer: 'resend')]
class WeeklySummary extends Notification implements ShouldQueue
{
    // ...
}
```

The attribute only has an effect when the notification is sent with `Notification::batch()` or `batchNow()`. `Notification::send()` and `$user->notify()` behave exactly as stock Laravel.

**Sent now:**
- Each notifiable is sent the notification on every channel, exactly as Laravel does it, including `shouldSend()`, `NotificationSending`, `NotificationSkipped`, locales, and one notification ID shared across a notifiable's channels.
- Mail is captured into one batch. Once the batch has been sent, each notifiable's `NotificationSent` (with the provider's response) or `NotificationFailed` event is dispatched, and `afterSending()` is called.
- If none of the messages could be sent, for example because the provider is down, a `BatchSendException` is thrown, so a queued job is retried. A partially sent batch doesn't throw, because retrying it would send the delivered messages again. Its failures are reported through `NotificationFailed`.
- If the transport declines a message without an error, as Symfony does when a `MessageEvent` listener rejects it, the notifiable gets `NotificationSent` with a `null` response, as it would from Laravel.

**Queued:**
- The mail channel's jobs are grouped into one `SendBatchedMailNotifications` job per batch of notifiables. That job is Laravel's own queued-notification job, so the notification's tries, timeout, backoff, encryption and `failed()` apply to it.
- Notifiables are only grouped when their jobs would have been queued with the same connection, queue, delay, message group and middleware, and their notification is in the same state. Models are only grouped with models of the same class on the same connection, so they're serialized as model identifiers. Jobs with a deduplicator are never grouped.
- Each notifiable keeps the notification ID it was queued with, so its mail shares an ID with its other channels.
- A batch job keeps its idempotency keys when it's retried, so `resend-batch` won't deliver its emails twice.
- Each batch job is delayed after the previous one, so the requests are spread out. This is best effort: most queues schedule to the second, and a slow or retried job can overlap the next. For a hard limit, add a rate-limiting job middleware such as `RateLimited`.
- Other channels, and a notifiable that can't be grouped with any other, are queued exactly as Laravel queues them.

## Testing

```php
Mail::fake();

Mail::batch($mailables);

MailBatch::assertBatched(fn ($mailables, $mailer) => $mailables->count() === 3);
MailBatch::assertBatchCount(1);
MailBatch::assertNotBatched();
Mail::assertSent(Digest::class, 3);
```

With `Mail::fake()`, no batched mail reaches a transport, including notification mail sent through a mail channel created before the fake. With `Notification::fake()`, `Notification::batch()` is recorded the way `Notification::send()` is, so `Notification::assertSentTo()` works as usual.

## Behaviour to be aware of

- **Event order.** Batched mail fires every `MessageSending` event first, while the batch is captured, and every `MessageSent` event after the batch is sent. A batched notification's mail events are dispatched after its other channels, and after later notifiables, have been handled.
- **Rendering time.** Messages are rendered when they're captured, not when they're sent.
- **Queued mailables.** A mailable that is queued, with `queue()`, `later()` or `ShouldQueue`, is queued as usual and isn't part of a `capture()` batch. Mailables passed to `send()` are always sent as part of the batch.
- **Batch requests skip Symfony's per-message hooks.** A request with several messages calls the transport's `sendBatch()` directly, so Symfony's `MessageEvent` listeners and the transport's own throttle don't apply to it. A request with one message goes through the transport's normal `send()`.
- **Failover, round robin and other wrapping transports** don't expose a batch API, so they send one message per request. Failover still works as usual.
- **Retries** use the same idempotency key on every attempt of a request. `resend-batch` sends it to Resend, so a retried request isn't delivered twice. With a transport that ignores the key, such as `postmark-batch`, a retry after a timeout can deliver a message twice.
- **A `MessageSent` listener that throws** doesn't stop the rest of the batch. Every request is still made, and a `BatchListenerException` holding the whole result is thrown at the end.
- **Concurrency.** A batch only captures mail sent from the coroutine or fiber that opened it, so it's safe under Octane, Swoole and RoadRunner.
- Batches can't be nested on the same mailer. A notification batch sent inside an open `Mail::batch()->capture()` joins that batch, and its notification events are dispatched once that batch has been sent.

## Writing a batch transport

Implement `NineteenNinetyFour\MailBatch\Contracts\BatchTransport` on your Symfony transport:

```php
public function maxBatchSize(): int;
public function canBatch(Email $email): bool;
public function sendBatch(array $messages, array $options = []): array; // returns failures keyed like $messages
```

## License

MIT.
