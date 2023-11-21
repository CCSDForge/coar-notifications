<?php

namespace cottagelabs\coarNotifications\orm;

use Throwable;

class COARNotificationNoDatabaseException extends COARNotificationException
{
    // Redefine the exception so message isn't optional
    public function __construct($message = "No database connection.", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}