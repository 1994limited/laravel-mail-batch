<?php

namespace NineteenNinetyFour\MailBatch\Capture;

use Fiber;
use Swoole\Coroutine;

/**
 * Identifies the unit of execution a capture belongs to.
 *
 * A mailer is shared by everything running in the process, so a capture is tied to the
 * coroutine or fiber that opened it. Mail sent by any other coroutine or fiber is sent
 * as usual, even while the capture is open, instead of being captured by mistake.
 */
class Context
{
    /**
     * Get the key of the current unit of execution.
     */
    public static function key(): string
    {
        $key = 'main';

        if (class_exists(Coroutine::class, false) && ($id = Coroutine::getCid()) > 0) {
            $key = "coroutine:{$id}";
        } elseif (class_exists(\OpenSwoole\Coroutine::class, false) && ($id = \OpenSwoole\Coroutine::getCid()) > 0) {
            $key = "coroutine:{$id}";
        }

        // A fiber started within a coroutine is a context of its own within that coroutine.
        if ($fiber = Fiber::getCurrent()) {
            $key .= ':fiber:'.spl_object_id($fiber);
        }

        return $key;
    }
}
