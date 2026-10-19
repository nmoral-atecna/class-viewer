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
    public function keepsExternalReferencesAsMarkedNodesWhenVendorIncluded(): void
    {
        // C2.1
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/external', true);

        self::assertSame(
            ['App\Foo', 'DateTimeImmutable', 'Psr\Log\LoggerInterface', 'Vendor\Lib\Client'],
            array_column($result->graph['nodes'], 'id')
        );
        self::assertSame(
            [
                ['from' => 'App\Foo', 'to' => 'DateTimeImmutable'],
                ['from' => 'App\Foo', 'to' => 'Psr\Log\LoggerInterface'],
                ['from' => 'App\Foo', 'to' => 'Vendor\Lib\Client'],
            ],
            $result->graph['edges']
        );
    }

    #[Test]
    public function marksOnlyExternalNodesWithExternalFlag(): void
    {
        // C3.1, C3.2, C2.2
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/external', true);

        $byId = array_column($result->graph['nodes'], null, 'id');

        self::assertArrayNotHasKey('external', $byId['App\Foo']);
        self::assertSame(
            ['id' => 'Psr\Log\LoggerInterface', 'name' => 'LoggerInterface', 'ns' => 'Psr\Log', 'kind' => 'class', 'file' => '', 'external' => true],
            $byId['Psr\Log\LoggerInterface']
        );
        self::assertSame(
            ['id' => 'DateTimeImmutable', 'name' => 'DateTimeImmutable', 'ns' => '', 'kind' => 'class', 'file' => '', 'external' => true],
            $byId['DateTimeImmutable']
        );
    }

    #[Test]
    public function doesNotMarkInternalDependenciesAsExternalWhenVendorIncluded(): void
    {
        // C2.2: App\Foo depends on App\Bar, both project nodes; no external flag, no duplicate.
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/dedup', true);

        $ids = array_column($result->graph['nodes'], 'id');
        self::assertSame(['App\Bar', 'App\Foo'], $ids);
        foreach ($result->graph['nodes'] as $node) {
            self::assertArrayNotHasKey('external', $node);
        }
    }

    #[Test]
    public function sortsExternalAndInternalNodesTogetherById(): void
    {
        // C2.3: unique list sorted by id, externals interleaved with internals.
        $result = (new GraphBuilder())->build(self::FIXTURES . '/edges/external_mixed', true);

        self::assertSame(
            ['Acme\Alpha', 'App\Bar', 'App\Zeta', 'Zzz\Omega'],
            array_column($result->graph['nodes'], 'id')
        );

        $byId = array_column($result->graph['nodes'], null, 'id');
        self::assertTrue($byId['Acme\Alpha']['external']);
        self::assertTrue($byId['Zzz\Omega']['external']);
        self::assertArrayNotHasKey('external', $byId['App\Bar']);
        self::assertArrayNotHasKey('external', $byId['App\Zeta']);
    }

    #[Test]
    public function egoFixtureHasFocusWithOneIncomingAndOneOutgoingEdge(): void
    {
        // C1.1, C2.1: App\Consumer -> App\Focus (incoming), App\Focus -> App\Dep (outgoing).
        $result = (new GraphBuilder())->build(self::FIXTURES . '/ego');

        self::assertSame(
            ['App\Consumer', 'App\Dep', 'App\Focus'],
            array_column($result->graph['nodes'], 'id')
        );
        self::assertSame(
            [
                ['from' => 'App\Consumer', 'to' => 'App\Focus'],
                ['from' => 'App\Focus', 'to' => 'App\Dep'],
            ],
            $result->graph['edges']
        );
    }

    #[Test]
    public function largeFixtureHasManyNodesButFocusWithOnlyThreeNeighbours(): void
    {
        // C3.1: ~300 classes, the focus is connected to exactly 3 neighbours.
        $result = (new GraphBuilder())->build(self::FIXTURES . '/large');

        self::assertCount(300, $result->graph['nodes']);
        self::assertSame(
            [
                ['from' => 'App\Focus', 'to' => 'App\Dep1'],
                ['from' => 'App\Focus', 'to' => 'App\Dep2'],
                ['from' => 'App\Focus', 'to' => 'App\Dep3'],
            ],
            $result->graph['edges']
        );
    }

    #[Test]
    public function deepFixtureIsADenseConnectedGraphForTheRadiusPerfCheck(): void
    {
        // C3.1: a connected `deep` graph — focus + 5 distance-1 neighbours,
        // each wired to >=40 distance-2 classes, >=200 at distance 2 overall.
        $graph = (new GraphBuilder())->build(self::FIXTURES . '/deep')->graph;

        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
            $adjacency[$edge['to']][] = $edge['from'];
        }

        $distance = ['App\Focus' => 0];
        $queue = ['App\Focus'];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach ($adjacency[$id] ?? [] as $other) {
                if (!isset($distance[$other])) {
                    $distance[$other] = $distance[$id] + 1;
                    $queue[] = $other;
                }
            }
        }

        $atOne = array_keys(array_filter($distance, static fn (int $d): bool => $d === 1));
        $atTwo = array_filter($distance, static fn (int $d): bool => $d === 2);

        self::assertCount(5, $atOne, 'focus must have exactly 5 distance-1 neighbours');
        self::assertGreaterThanOrEqual(200, count($atTwo), 'at least 200 classes at distance 2');
        foreach ($atOne as $neighbour) {
            $farFromNeighbour = array_filter(
                $adjacency[$neighbour] ?? [],
                static fn (string $other): bool => ($distance[$other] ?? 0) === 2
            );
            self::assertGreaterThanOrEqual(
                40,
                count(array_unique($farFromNeighbour)),
                $neighbour . ' must reach at least 40 distance-2 classes'
            );
        }
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
