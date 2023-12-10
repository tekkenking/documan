<?php


namespace Tekkenking\Documan;

use Exception;
use Throwable;

class DocumanException extends Exception
{

    /**
     * @var mixed|string
     */
    protected $message;

    /**
     * @var
     */
    protected $code;

    /**
     * @param $message
     * @param $code
     * @param Throwable|null $previous
     */
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->message = $message;
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
    public function render()
    {
        return $this->message;
    }

}
