<?php

namespace App\Exceptions;

use RuntimeException;

final class AnalysisAlreadyActiveException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El análisis ya está en curso o en cola.');
    }
}
