<?php


namespace Tekkenking\Documan;

use Exception;
use Throwable;

class DocumanException extends Exception
{

    /**
     * @param $message
     * @param $code
     * @param Throwable|null $previous
     */
    public function __construct($message = "", $code = 0, ?Throwable $previous = null)
    {
        parent::__construct((string) $message, (int) $code, $previous);
    }

    /**
     * Report the exception.
     *
     * @return bool|null
     */
    public function report(): ?bool
    {
        return false;
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @return \Illuminate\Http\Response
     */
    public function render(): \Illuminate\Http\Response
    {
        return response($this->message, 422, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

}
