<?php

namespace App\Enums;

enum StocksErrorType: string
{
    case Temporary = 'temporary';
    case Item = 'item';
    case Request = 'request';
}
