<?php

declare(strict_types=1);

namespace App;

class Bar
{
}

class Foo
{
    private Bar $bar;

    public function __construct(Bar $bar)
    {
        $this->bar = $bar;
    }

    public function clone(): self
    {
        return new Foo();
    }
}
