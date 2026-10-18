<?php

namespace NineteenNinetyFour\MailBatch\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use NineteenNinetyFour\MailBatch\MailBatchServiceProvider;
use NineteenNinetyFour\MailBatch\Tests\Fixtures\RecordingBatchTransport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected RecordingBatchTransport $transport;

    protected function getPackageProviders($app): array
    {
        return [MailBatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('mail.default', 'batched');
        $app['config']->set('mail.mailers.batched', ['transport' => 'recording']);
        $app['config']->set('mail.mailers.array', ['transport' => 'array']);
        $app['config']->set('mail.from', ['address' => 'hello@laravel.com', 'name' => 'Laravel']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->transport = new RecordingBatchTransport;

        Mail::extend('recording', fn () => $this->transport);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
