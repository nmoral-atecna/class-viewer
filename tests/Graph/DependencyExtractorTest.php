<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Graph;

use ClassViewer\Graph\NodeExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DependencyExtractorTest extends TestCase
{
    /**
     * @return list<array{from: string, to: string}>
     */
    private function edges(string $code): array
    {
        return (new NodeExtractor())->extractEdges($code);
    }

    #[Test]
    public function constructorPromotedParameter(): void
    {
        // C1.1
        $code = '<?php namespace App\Service;
            use App\Repository\BarRepository;
            class Foo { public function __construct(private BarRepository $bar) {} }';

        self::assertSame(
            [['from' => 'App\Service\Foo', 'to' => 'App\Repository\BarRepository']],
            $this->edges($code)
        );
    }

    #[Test]
    public function typedProperty(): void
    {
        // C1.2
        $code = '<?php namespace App\Service;
            use App\Log\Logger;
            class Foo { private Logger $logger; }';

        self::assertSame(
            [['from' => 'App\Service\Foo', 'to' => 'App\Log\Logger']],
            $this->edges($code)
        );
    }

    #[Test]
    public function bodyReferenceViaNewAndClassConst(): void
    {
        // C1.3 — one edge even if referenced twice
        $code = '<?php namespace App\Service;
            use App\Factory\Thing;
            class Foo {
                public function make(): void { $t = new Thing(); $n = Thing::class; }
            }';

        self::assertSame(
            [['from' => 'App\Service\Foo', 'to' => 'App\Factory\Thing']],
            $this->edges($code)
        );
    }

    #[Test]
    public function unionNullableIntersectionTypes(): void
    {
        // C1.4
        $code = '<?php namespace App\Service;
            use App\A; use App\B; use App\C; use App\D;
            class Foo {
                public function h(A|B $x, ?C $y, A&D $z): void {}
            }';

        self::assertSame(
            [
                ['from' => 'App\Service\Foo', 'to' => 'App\A'],
                ['from' => 'App\Service\Foo', 'to' => 'App\B'],
                ['from' => 'App\Service\Foo', 'to' => 'App\C'],
                ['from' => 'App\Service\Foo', 'to' => 'App\D'],
            ],
            $this->edges($code)
        );
    }

    #[Test]
    public function nonConstructorMethodParamAndReturn(): void
    {
        // C1.5
        $code = '<?php namespace App\Service;
            use App\Event\SomeEvent; use App\Result\SomeResult;
            class Foo {
                public function handle(SomeEvent $e): SomeResult {}
            }';

        self::assertSame(
            [
                ['from' => 'App\Service\Foo', 'to' => 'App\Event\SomeEvent'],
                ['from' => 'App\Service\Foo', 'to' => 'App\Result\SomeResult'],
            ],
            $this->edges($code)
        );
    }

    #[Test]
    public function staticCallAndStaticPropertyAndInstanceof(): void
    {
        // C1 (Q1=B): static call, static property, instanceof
        $code = '<?php namespace App\Service;
            use App\X; use App\Y; use App\Z;
            class Foo {
                public function h($o): void {
                    X::run();
                    $v = Y::$shared;
                    $b = $o instanceof Z;
                }
            }';

        self::assertSame(
            [
                ['from' => 'App\Service\Foo', 'to' => 'App\X'],
                ['from' => 'App\Service\Foo', 'to' => 'App\Y'],
                ['from' => 'App\Service\Foo', 'to' => 'App\Z'],
            ],
            $this->edges($code)
        );
    }

    #[Test]
    public function heritageProducesNoEdge(): void
    {
        // C4.1 + C4.2
        $code = '<?php namespace App;
            class Foo extends BaseKernel implements Handler { use Timestampable; }';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function selfParentStaticAndOwnNameProduceNoEdge(): void
    {
        // C3.2
        $code = '<?php namespace App\Service;
            class Foo {
                public function copy(self $f): void {
                    $a = new self();
                    $b = new Foo();
                    $c = new static();
                    parent::boot();
                }
            }';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function internalAttribute(): void
    {
        // C6.1
        $code = '<?php namespace App\Service;
            use App\Attribute\Audited;
            #[Audited]
            class Foo {}';

        self::assertSame(
            [['from' => 'App\Service\Foo', 'to' => 'App\Attribute\Audited']],
            $this->edges($code)
        );
    }

    #[Test]
    public function keepsRawDuplicatesAndExternalRefsForLaterFiltering(): void
    {
        // raw collector does not filter vendor/native; GraphBuilder does that.
        $code = '<?php namespace App\Service;
            class Foo {
                public function __construct(private \DateTimeImmutable $d) {}
            }';

        self::assertSame(
            [['from' => 'App\Service\Foo', 'to' => 'DateTimeImmutable']],
            $this->edges($code)
        );
    }
}
