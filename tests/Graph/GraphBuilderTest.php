<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Graph;

use ClassViewer\Graph\GraphBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GraphBuilderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/graph';

    #[Test]
    public function buildsGraphWithVersionAndEmptyEdges(): void
    {
        // C1.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/nominal');

        self::assertSame(1, $result->graph['version']);
        self::assertSame([], $result->graph['edges']);
        self::assertCount(4, $result->graph['nodes']);
        self::assertSame([], $result->warnings);
    }

    #[Test]
    public function sortsNodesByIdAscending(): void
    {
        // C1.2
        $result = (new GraphBuilder())->build(self::FIXTURES . '/nominal');

        $ids = array_column($result->graph['nodes'], 'id');

        self::assertSame([
            'App\Behavior\Baz',
            'App\Contract\Bar',
            'App\Service\Foo',
            'App\Status',
        ], $ids);
    }

    #[Test]
    public function emitsWarningAndSkipsUnparsableFile(): void
    {
        // C5.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/parse_error');

        self::assertSame(['App\Valid'], array_column($result->graph['nodes'], 'id'));
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('src/Broken.php', $result->warnings[0]);
    }

    #[Test]
    public function keepsFirstNodeOnDuplicateFqcnAndWarns(): void
    {
        // C6.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/dup');

        // App\Dup kept from first file; App\After (declared after the duplicate) still collected.
        self::assertSame(['App\After', 'App\Dup'], array_column($result->graph['nodes'], 'id'));
        $dup = array_values(array_filter(
            $result->graph['nodes'],
            static fn (array $node): bool => 'App\Dup' === $node['id']
        ))[0];
        self::assertSame('src/Dup1.php', $dup['file']);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('App\Dup', $result->warnings[0]);
    }

    #[Test]
    public function sortsNodesByIdEvenWhenScanOrderDiffers(): void
    {
        // C1.2: scan order (a.php -> App\Zzz, z.php -> App\Aaa) differs from id order
        $result = (new GraphBuilder())->build(self::FIXTURES . '/order');

        self::assertSame(['App\Aaa', 'App\Zzz'], array_column($result->graph['nodes'], 'id'));
    }

    #[Test]
    public function excludesVendorAndNonPhpFiles(): void
    {
        // C1: reuse existing discovery (vendor excluded, only .php kept)
        $result = (new GraphBuilder())->build(self::FIXTURES . '/discovery');

        self::assertSame(['App\Kept'], array_column($result->graph['nodes'], 'id'));
    }

    #[Test]
    public function stripsTrailingSlashFromTargetPath(): void
    {
        // C2.1 file path must stay relative even with a trailing slash
        $result = (new GraphBuilder())->build(self::FIXTURES . '/discovery/');

        self::assertSame('src/Kept.php', $result->graph['nodes'][0]['file']);
    }

    #[Test]
    public function buildsEmptyGraphForProjectWithoutDeclaration(): void
    {
        // C7.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/empty');

        self::assertSame(
            ['version' => 1, 'nodes' => [], 'edges' => []],
            $result->graph
        );
    }

    #[Test]
    public function emitsInternalEdgesFromConstructorAndProperty(): void
    {
        // C1.1, C1.2, C2, C5
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/nominal');

        self::assertSame(
            [
                ['from' => 'App\Service\Foo', 'to' => 'App\Log\Logger'],
                ['from' => 'App\Service\Foo', 'to' => 'App\Repository\BarRepository'],
            ],
            $result->graph['edges']
        );
    }

    #[Test]
    public function dropsReferencesToVendorAndNativeClasses(): void
    {
        // C2.1, C2.2
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/external');

        self::assertSame([], $result->graph['edges']);
    }

    #[Test]
    public function dedupesEdgesAndExcludesSelfReference(): void
    {
        // C3.1, C3.2
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/dedup');

        self::assertSame(
            [['from' => 'App\Foo', 'to' => 'App\Bar']],
            $result->graph['edges']
        );
    }

    #[Test]
    public function sortsEdgesByFromThenTo(): void
    {
        // C5.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/sort');

        self::assertSame(
            [
                ['from' => 'App\Early', 'to' => 'App\Mid'],
                ['from' => 'App\Foo', 'to' => 'App\Alpha'],
                ['from' => 'App\Foo', 'to' => 'App\Mid'],
                ['from' => 'App\Foo', 'to' => 'App\Zeta'],
            ],
            $result->graph['edges']
        );
    }
}
