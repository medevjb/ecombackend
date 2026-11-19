<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ApiProblem extends HttpException
{
    public function __construct(public readonly string $errorCode, string $message, int $status = 409)
    {
        parent::__construct($status, $message);
    }
}
