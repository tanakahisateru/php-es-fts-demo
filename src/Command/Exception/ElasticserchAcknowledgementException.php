<?php
namespace App\Command\Exception;

use Exception;
use Throwable;

class ElasticserchAcknowledgementException extends Exception
{
    public function __construct($response, $code = 0, Throwable $previous = null)
    {
        $message = json_encode($response, JSON_PRETTY_PRINT);
        parent::__construct($message, $code, $previous);
    }
}