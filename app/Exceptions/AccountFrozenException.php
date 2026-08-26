<?php

namespace App\Exceptions;

use Exception;

class AccountFrozenException extends Exception
{
    public function __construct()
    {
        parent::__construct('This account is frozen and cannot transact.');
    }
}
