<?php

namespace App\Enums;

enum CurrencyType: string
{
    case FIAT = 'fiat';
    case CRYPTO = 'crypto';
}
