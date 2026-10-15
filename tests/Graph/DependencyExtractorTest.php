<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Graph;

use ClassViewer\Graph\NodeExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DependencyExtractorTest extends TestCase
{
    /**
     * Raw-collection order and duplicates are implementation details (GraphBuilder sorts
     * and dedupes), so edges are deduped and sorted here to keep assertions stable.
     *
     * @return list<array{from: string, to: string}>
     */
    private function edges(string $code): array
    {
        $unique = [];
        foreach ((new NodeExtractor())->extractEdges($code) as $edge) {
            $unique[$edge['from'] . "\0" . $edge['to']] = $edge;
        }

        $edges = array_values($unique);
        usort(
            $edges,
            static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]
        );

        return $edges;
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
    public function selfParentStaticProduceNoEdge(): void
    {
        // C3.2 — special class names are dropped at collection time.
        // The own-FQCN case (new Foo() inside Foo) is dropped end-to-end by GraphBuilder.
        $code = '<?php namespace App\Service;
            class Foo {
                public function copy(self $f): void {
                    $a = new self();
                    $c = new static();
                    parent::boot();
                }
            }';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function newExpressionProducesEdge(): void
    {
        // C1.3 (New_)
        $code = '<?php namespace App; use App\Thing; class Foo { public function m() { new Thing(); } }';

        self::assertSame([['from' => 'App\Foo', 'to' => 'App\Thing']], $this->edges($code));
    }

    #[Test]
    public function classConstFetchProducesEdge(): void
    {
        // C1.3 (::class / ::CONST)
        $code = '<?php namespace App; use App\Thing; class Foo { public function m() { Thing::class; } }';

        self::assertSame([['from' => 'App\Foo', 'to' => 'App\Thing']], $this->edges($code));
    }

    #[Test]
    public function staticCallProducesEdge(): void
    {
        // C1 (StaticCall)
        $code = '<?php namespace App; use App\Thing; class Foo { public function m() { Thing::run(); } }';

        self::assertSame([['from' => 'App\Foo', 'to' => 'App\Thing']], $this->edges($code));
    }

    #[Test]
    public function staticPropertyFetchProducesEdge(): void
    {
        // C1 (StaticPropertyFetch)
        $code = '<?php namespace App; use App\Thing; class Foo { public function m() { return Thing::$shared; } }';

        self::assertSame([['from' => 'App\Foo', 'to' => 'App\Thing']], $this->edges($code));
    }

    #[Test]
    public function instanceofProducesEdge(): void
    {
        // C1 (Instanceof_)
        $code = '<?php namespace App; use App\Thing; class Foo { public function m($o) { return $o instanceof Thing; } }';

        self::assertSame([['from' => 'App\Foo', 'to' => 'App\Thing']], $this->edges($code));
    }

    #[Test]
    public function dynamicClassExpressionProducesNoEdge(): void
    {
        // $node->class may be an Expr (dynamic) rather than a Name: no edge.
        $code = '<?php namespace App; class Foo { public function m($cls) { return new $cls(); } }';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function referenceOutsideAnyClassProducesNoEdge(): void
    {
        // Empty class stack: a top-level reference has no "from".
        $code = '<?php namespace App; use App\Thing; new Thing();';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function referenceInsideAnonymousClassProducesNoEdge(): void
    {
        // Anonymous classes have no FQCN node, so references inside them yield no edge.
        $code = '<?php namespace App; use App\Thing; $x = new class { public function m() { new Thing(); } };';

        self::assertSame([], $this->edges($code));
    }

    #[Test]
    public function siblingClassesKeepTheirOwnFrom(): void
    {
        // Leaving a class pops the stack, so the next sibling uses its own FQCN.
        $code = '<?php namespace App;
            use App\Thing;
            class A { public function m(): Thing {} }
            class B { public function n(): Thing {} }';

        self::assertSame(
            [
                ['from' => 'App\A', 'to' => 'App\Thing'],
                ['from' => 'App\B', 'to' => 'App\Thing'],
            ],
            $this->edges($code)
        );
    }

    #[Test]
    public function referenceAfterNestedAnonymousClassBindsToOuterClass(): void
    {
        // Leaving the anonymous class must pop the stack, restoring the outer "from".
        $code = '<?php namespace App;
            use App\Thing;
            class Outer {
                public function m() {
                    $x = new class {};
                    return new Thing();
                }
            }';

        self::assertSame([['from' => 'App\Outer', 'to' => 'App\Thing']], $this->edges($code));
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
