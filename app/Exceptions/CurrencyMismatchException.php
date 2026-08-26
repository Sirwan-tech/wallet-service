<?php

namespace App\Exceptions;

use Exception;

class CurrencyMismatchException extends Exception
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Currency mismatch: cannot operate between {$from} and {$to}.");
    }
}
