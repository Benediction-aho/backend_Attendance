<?php

namespace App\Services\Push;

enum PushResult
{
    case Sent;
    /** 404 / 410: the subscription no longer exists and must be deleted. */
    case Expired;
    case Failed;
}
