<?php

declare(strict_types=1);

namespace App;

class Foo
{
    public function __construct(
        private \DateTimeImmutable $when,
        private \Psr\Log\LoggerInterface $log,
    ) {
    }

    public function call(): void
    {
        $client = new \Vendor\Lib\Client();
    }
}
