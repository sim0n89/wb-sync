<?php

namespace App\Enums;

enum StockUploadStatus: string
{
    case InProgress = 'in_progress';
    case Sent = 'sent';
    case Failed = 'failed';
}
