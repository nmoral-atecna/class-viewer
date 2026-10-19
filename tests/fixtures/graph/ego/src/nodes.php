<?php

declare(strict_types=1);

namespace App;

class Dep
{
}

class Focus
{
    // Outgoing edge: Focus -> Dep.
    public function __construct(private Dep $dep)
    {
    }
}

class Consumer
{
    // Incoming edge: Consumer -> Focus.
    public function __construct(private Focus $focus)
    {
    }
}
