<?php

declare(strict_types=1);

namespace Tekkenking\Documan;

use Illuminate\Support\Facades\Facade;

class DocumanFacade extends Facade
{

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor() { return 'documan'; }
}
