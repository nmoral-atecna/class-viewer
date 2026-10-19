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

    #[Test]
    public function rendersARadiusRangeSliderDefaultingToOne(): void
    {
        // C1.1 (#9): a <input type="range"> with min=1 max=3 value=1 drives the neighbourhood radius.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);

        self::assertSame(
            1,
            preg_match('#<input[^>]*type="range"[^>]*>#', $html, $m),
            'a range input must be present'
        );
        $slider = $m[0];
        self::assertStringContainsString('min="1"', $slider);
        self::assertStringContainsString('max="3"', $slider);
        self::assertStringContainsString('value="1"', $slider);
        self::assertStringContainsString('id="radius"', $slider);
    }

    #[Test]
    public function collectsNodesByUndirectedBfsUpToTheRadius(): void
    {
        // C1.1/C1.2/C1.3 (#9): draw() does an undirected BFS (both edge senses) bounded by the radius.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);
        $script = $this->mainScript($html);

        // The slider value feeds the radius bound.
        self::assertMatchesRegularExpression(
            '#parseInt\(\s*(document\.getElementById\(.radius.\)|radius)[^)]*\.value#',
            $script,
            'the radius comes from the slider value'
        );
        // BFS explores both directions of each edge (from<->to), not only the focus-centred sense.
        self::assertStringContainsString('edge.from', $script);
        self::assertStringContainsString('edge.to', $script);
        // Per-node distance is tracked and bounded by the radius before expanding further.
        self::assertStringContainsString('dist', $script);
        self::assertMatchesRegularExpression('#dist\[[^\]]+\]\s*>=\s*maxRadius#', $script);
    }

    #[Test]
    public function rendersFarNodesAsDimPointsWithoutLabelOrClick(): void
    {
        // C2.1/C2.2 (#9): nodes at distance >= 2 are dim points, no label, not re-centring on click.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);
        $script = $this->mainScript($html);

        // A dedicated markup for far nodes: a class to style (dim) and mark them non-clickable.
        self::assertStringContainsString('far', $script, 'far nodes get a dedicated class');
        // Click handler ignores far points when re-centring.
        self::assertStringContainsString('far', $this->clickHandler($script));
        // The far-node markup carries no <text> label (checked structurally below in the style).
        self::assertStringContainsString('.far', $html);
        self::assertMatchesRegularExpression('#\.far[^{]*\{[^}]*opacity#', $html, 'far points are dimmed via opacity');
    }

    private function mainScript(string $html): string
    {
        self::assertSame(
            1,
            preg_match('#<script>\s*\(function \(\).*?</script>#s', $html, $m),
            'the main behaviour <script> must be present'
        );

        return $m[0];
    }

    private function clickHandler(string $script): string
    {
        self::assertSame(
            1,
            preg_match('#app\.addEventListener\(.click.,.*?\}\);#s', $script, $m),
            'the app click handler must be present'
        );

        return $m[0];
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
