<?php

declare(strict_types=1);

namespace App\Service;

use App\Log\Logger;
use App\Repository\BarRepository;

class Foo
{
    private Logger $logger;

    public function __construct(private BarRepository $bar)
    {
    }
}
