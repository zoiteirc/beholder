<?php

namespace App\Persistence\Exceptions;

use Throwable;

class PdoPersistenceException extends PersistenceException
{
    public function __construct(\PDO $connectionResource, Throwable $previous = null)
    {
        parent::__construct(
            implode(' ', $connectionResource->errorInfo()),
            $connectionResource->errorCode(),
            $previous,
        );
    }
}
