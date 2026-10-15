<?php

declare(strict_types=1);

namespace App;

class Zeta
{
}

class Alpha
{
}

class Mid
{
}

class Foo
{
    public function use(Zeta $z, Alpha $a, Mid $m): void
    {
    }
}

class Early
{
    public function need(Mid $m): void
    {
    }
}
