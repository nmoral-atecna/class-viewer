<?php

declare(strict_types=1);

namespace App;

class Bar
{
}

class Zeta
{
    // Self-reference (skipped) and an internal reference (skipped in external pass)
    // both precede external references, so the external pass must `continue`, not `break`.
    public function __construct(
        private Zeta $self,
        private Bar $bar,
        private \Acme\Alpha $alpha,
        private \Zzz\Omega $omega,
    ) {
    }
}
