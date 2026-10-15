<?php

declare(strict_types=1);

namespace App;

class Bar
{
}

class Foo
{
    // A dropped reference (self) precedes a kept one, so dedup must `continue`, not `break`.
    public function __construct(Foo $self, Bar $bar)
    {
        $this->bar = $bar;
    }

    // Bar referenced twice (param + property) to exercise dedup.
    private Bar $bar;
}
