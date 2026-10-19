<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Html;

use ClassViewer\Html\HtmlRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlRendererTest extends TestCase
{
    #[Test]
    public function embedsTheInputGraphVerbatimInTheJsonScriptTag(): void
    {
        // Contract: render() embeds the given graph as-is in <script type="application/json" id="graph">.
        $graph = [
            'version' => 1,
            'nodes' => [
                ['id' => 'App\\Service\\Foo', 'ns' => 'App\\Service'],
                ['id' => 'App\\BarRepository', 'ns' => 'App'],
                ['id' => 'App\\Logger', 'ns' => 'App'],
            ],
            'edges' => [
                ['from' => 'App\\Service\\Foo', 'to' => 'App\\BarRepository'],
                ['from' => 'App\\Service\\Foo', 'to' => 'App\\Logger'],
            ],
        ];

        $decoded = $this->embeddedGraph((new HtmlRenderer())->render($graph));

        self::assertSame($graph, $decoded);
    }

    #[Test]
    public function escapesAngleBracketsSoTheScriptTagCannotBreak(): void
    {
        // C3.2 (#5): embedded content containing `<` must not break the <script> tag.
        $graph = ['version' => 1, 'nodes' => [['id' => 'A</script>B<x']], 'edges' => []];

        $html = (new HtmlRenderer())->render($graph);

        self::assertSame(1, preg_match('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $m));
        self::assertStringNotContainsString('</script', $m[1]);
        self::assertStringNotContainsString('<x', $m[1]);

        self::assertSame($graph, $this->embeddedGraph($html));
    }

    #[Test]
    public function showsAnEmptyMessageWhenThereIsNoNode(): void
    {
        // C1.3: a project with 0 node shows an "aucun nœud" message, no mute empty SVG.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);

        self::assertStringContainsString('aucun', $html);
    }

    #[Test]
    public function keepsTheDocumentSelfContained(): void
    {
        // C4.1 (regression #5): no external reference, no fetch anywhere in the document.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);

        self::assertDoesNotMatchRegularExpression('#https?://#', $html);
        self::assertStringNotContainsString('fetch(', $html);
        self::assertStringNotContainsString('<link', $html);
        self::assertDoesNotMatchRegularExpression('#\ssrc=#', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddedGraph(string $html): array
    {
        self::assertSame(
            1,
            preg_match('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $m),
            'the JSON graph <script> tag must be present'
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
