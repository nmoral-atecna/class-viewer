<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Html;

use ClassViewer\Html\HtmlRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlRendererTest extends TestCase
{
    #[Test]
    public function escapesAngleBracketsSoTheScriptTagCannotBreak(): void
    {
        // C3.2 (#5): embedded content containing `<` must not break the <script> tag.
        $graph = ['version' => 1, 'nodes' => [['id' => 'A</script>B<x']], 'edges' => []];

        $html = (new HtmlRenderer())->render($graph);

        self::assertSame(1, preg_match('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $m));
        self::assertStringNotContainsString('</script', $m[1]);
        self::assertStringNotContainsString('<x', $m[1]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($graph, $decoded);
    }

    #[Test]
    public function inlinesVanillaViewerThatReadsTheEmbeddedJsonIntoAnSvg(): void
    {
        // C1/C4: the viewer reads the embedded JSON tag (no fetch) and renders into #app an <svg>.
        $script = $this->inlineScript((new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]));

        self::assertMatchesRegularExpression('#getElementById\(\s*["\']graph["\']\s*\)#', $script);
        self::assertStringContainsString('JSON.parse', $script);
        self::assertStringContainsString("getElementById('app')", $script);
        self::assertStringContainsString('svg', $script);
    }

    #[Test]
    public function picksTheMostConnectedNodeAsFocusDetailedWithFqcnAndNamespace(): void
    {
        // C1.1 / C1.2: focus = highest degree (in+out). Focus labelled with FQCN and namespace.
        $script = $this->inlineScript((new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]));

        // degree is computed from both directions over the edges
        self::assertMatchesRegularExpression('#\bfrom\b#', $script);
        self::assertMatchesRegularExpression('#\bto\b#', $script);
        self::assertMatchesRegularExpression('#degree|degrees#', $script);
        // focus label uses the full id (FQCN) and the namespace, not the short name alone
        self::assertMatchesRegularExpression('#\.id\b#', $script);
        self::assertMatchesRegularExpression('#\.ns\b#', $script);
    }

    #[Test]
    public function breaksFocusTiesBySmallestId(): void
    {
        // C1.2: equal degree is resolved by the smallest id.
        $script = $this->inlineScript((new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]));

        self::assertMatchesRegularExpression('#\.id\s*<\s*\w+\.id#', $script);
    }

    #[Test]
    public function rendersOnlyTheFocusNeighbourhoodNotTheWholeGraph(): void
    {
        // C3.1: only focus + direct neighbours are instantiated as SVG nodes.
        $script = $this->inlineScript((new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]));

        // neighbours are those adjacent to the focus id in either direction
        self::assertMatchesRegularExpression('#neighbou?rs?#i', $script);
        // and the renderer places them radially around the focus (deterministic layout)
        self::assertMatchesRegularExpression('#Math\.cos#', $script);
        self::assertMatchesRegularExpression('#Math\.sin#', $script);
    }

    #[Test]
    public function distinguishesInAndOutByArrowDirectionOnly(): void
    {
        // C2.1: outgoing arrow points to the neighbour, incoming arrow points to the focus.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);
        $script = $this->inlineScript($html);

        // an SVG marker is used to draw the arrow head
        self::assertStringContainsString('marker', $script);
        self::assertMatchesRegularExpression('#marker-end|markerEnd#', $script);
        // the two senses read the edge endpoints to orient the line (focus->neighbour vs neighbour->focus)
        self::assertMatchesRegularExpression('#\bfrom\b#', $script);
        self::assertMatchesRegularExpression('#\bto\b#', $script);
    }

    #[Test]
    public function showsAnEmptyMessageWhenThereIsNoNode(): void
    {
        // C1.3: a project with 0 node shows an "aucun nœud" message, no mute empty SVG.
        $script = $this->inlineScript((new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]));

        self::assertStringContainsString('aucun', $script);
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

    private function inlineScript(string $html): string
    {
        self::assertSame(
            1,
            preg_match('#<script>(?!.*type="application/json")(.*?)</script>#s', $html, $m),
            'a non-JSON inline <script> must be present'
        );

        return $m[1];
    }
}
