<?php

namespace App\Enums;

enum Exchange: string
{
    case NYSE = 'NYSE';
    case NASDAQ = 'NASDAQ';
    case TSX = 'TSX';
    case TSXV = 'TSXV';
    case CBOE = 'CBOE';
    case OTC = 'OTC';
    case ASX = 'ASX';
    case LSE = 'LSE';
    case FRA = 'FRA';
}
