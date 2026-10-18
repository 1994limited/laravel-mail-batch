<?php

namespace NineteenNinetyFour\MailBatch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class NotifiableUser extends Model
{
    use Notifiable;

    public $table = 'users';

    public $timestamps = false;
}
