<?php

namespace NineteenNinetyFour\MailBatch\Facades;

use Illuminate\Support\Facades\Facade;
use NineteenNinetyFour\MailBatch\MailBatcher;

/**
 * @method static \NineteenNinetyFour\MailBatch\PendingBatch mailer(string|null $name = null)
 * @method static \NineteenNinetyFour\MailBatch\BatchResult send(iterable $mailables)
 * @method static \NineteenNinetyFour\MailBatch\BatchResult capture(\Closure $callback)
 * @method static bool capturing(\Illuminate\Mail\Mailer $mailer)
 * @method static void assertBatched(\Closure|null $callback = null)
 * @method static void assertNotBatched()
 * @method static void assertBatchCount(int $count)
 *
 * @see MailBatcher
 */
class MailBatch extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return MailBatcher::class;
    }
}
