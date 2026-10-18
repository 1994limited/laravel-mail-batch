<?php

namespace NineteenNinetyFour\MailBatch\Tests\Fixtures;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\BusFake;

/**
 * A bus fake that also keeps every dispatched command in the order it was dispatched.
 */
class SequencedBusFake extends BusFake
{
    public array $sequence = [];

    public function dispatch($command)
    {
        $this->sequence[] = $command;

        return parent::dispatch($command);
    }

    public function sequence(): Collection
    {
        return new Collection($this->sequence);
    }
}
