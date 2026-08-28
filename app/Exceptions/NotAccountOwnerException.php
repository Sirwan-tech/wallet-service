<?php

namespace App\Exceptions;

use Exception;

class NotAccountOwnerException extends Exception
{
    public function __construct()
    {
        parent::__construct('You may only act on your own account.');
    }
}
