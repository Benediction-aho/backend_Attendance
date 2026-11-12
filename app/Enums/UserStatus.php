<?php

namespace App\Enums;

enum UserStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Blocked = 'blocked';
    case Locked = 'locked';
    case Deleted = 'deleted';
}
