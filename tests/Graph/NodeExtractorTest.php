<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Graph;

use ClassViewer\Graph\NodeExtractor;
use PhpParser\Error;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NodeExtractorTest extends TestCase
{
    #[Test]
    public function extractsClassNode(): void
    {
        // C2.1
        $nodes = (new NodeExtractor())->extract(
            '<?php namespace App\Service; class Foo {}',
            'src/Service/Foo.php'
        );

        self::assertSame([[
            'id' => 'App\Service\Foo',
            'name' => 'Foo',
            'ns' => 'App\Service',
            'kind' => 'class',
            'file' => 'src/Service/Foo.php',
        ]], $nodes);
    }

    #[Test]
    public function extractsInterfaceNode(): void
    {
        // C2.2
        $nodes = (new NodeExtractor())->extract(
            '<?php namespace App\Contract; interface Bar {}',
            'src/Contract/Bar.php'
        );

        self::assertSame('interface', $nodes[0]['kind']);
        self::assertSame('App\Contract\Bar', $nodes[0]['id']);
    }

    #[Test]
    public function extractsEnumNode(): void
    {
        // C2.3
        $nodes = (new NodeExtractor())->extract(
            '<?php namespace App; enum Status {}',
            'src/Status.php'
        );

        self::assertSame('enum', $nodes[0]['kind']);
        self::assertSame('App\Status', $nodes[0]['id']);
    }

    #[Test]
    public function extractsTraitNode(): void
    {
        // C2.4
        $nodes = (new NodeExtractor())->extract(
            '<?php namespace App\Behavior; trait Baz {}',
            'src/Behavior/Baz.php'
        );

        self::assertSame('trait', $nodes[0]['kind']);
        self::assertSame('App\Behavior\Baz', $nodes[0]['id']);
    }

    #[Test]
    public function extractsSeveralDeclarationsInOneFile(): void
    {
        // C2.5
        $nodes = (new NodeExtractor())->extract(
            '<?php namespace App; class A {} class B {}',
            'src/AB.php'
        );

        self::assertSame('App\A', $nodes[0]['id']);
        self::assertSame('App\B', $nodes[1]['id']);
        self::assertSame('src/AB.php', $nodes[0]['file']);
        self::assertSame('src/AB.php', $nodes[1]['file']);
    }

    #[Test]
    public function extractsGlobalNamespaceNode(): void
    {
        // C3.1
        $nodes = (new NodeExtractor())->extract('<?php class Loose {}', 'src/Loose.php');

        self::assertSame([[
            'id' => 'Loose',
            'name' => 'Loose',
            'ns' => '',
            'kind' => 'class',
            'file' => 'src/Loose.php',
        ]], $nodes);
    }

    #[Test]
    public function resolvesFullyQualifiedName(): void
    {
        // C3.2
        $nodes = (new NodeExtractor())->extract('<?php namespace A\B; class C {}', 'src/C.php');

        self::assertSame('A\B\C', $nodes[0]['id']);
    }

    #[Test]
    public function ignoresAnonymousClass(): void
    {
        // C4.1
        $nodes = (new NodeExtractor())->extract('<?php $x = new class {};', 'src/Anon.php');

        self::assertSame([], $nodes);
    }

    #[Test]
    public function ignoresFileWithoutDeclaration(): void
    {
        // C4.2
        $nodes = (new NodeExtractor())->extract(
            '<?php function helper(): void {} const ANSWER = 42;',
            'src/funcs.php'
        );

        self::assertSame([], $nodes);
    }

    #[Test]
    public function throwsOnInvalidSyntax(): void
    {
        // C5.1
        $this->expectException(Error::class);

        (new NodeExtractor())->extract('<?php class Broken {', 'src/Broken.php');
    }
}
