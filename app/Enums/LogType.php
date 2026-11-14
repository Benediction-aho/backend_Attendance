<?php

namespace App\Enums;

enum LogType: string
{
    case Action = 'action';
    case Error = 'error';
    case Security = 'security';
    case Automatic = 'automatic';
}
