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
        // C3.2: embedded content containing `<` must not break the <script> tag.
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
    public function inlinesVanillaViewerScriptThatReadsTheEmbeddedJson(): void
    {
        // C5: a vanilla JS viewer reads the embedded JSON and renders counts + sorted FQCN into #app.
        $html = (new HtmlRenderer())->render(['version' => 1, 'nodes' => [], 'edges' => []]);

        self::assertSame(
            1,
            preg_match('#<script>(?!.*type="application/json")(.*?)</script>#s', $html, $m),
            'a non-JSON inline <script> must be present'
        );
        $script = $m[1];

        // reads the embedded JSON tag, not a fetch/XHR
        self::assertMatchesRegularExpression('#getElementById\(\s*["\']graph["\']\s*\)#', $script);
        self::assertStringContainsString('JSON.parse', $script);
        self::assertStringContainsString("getElementById('app')", $script);

        // counts nodes and edges, and lists the node ids
        self::assertStringContainsString('nodes.length', $script);
        self::assertStringContainsString('edges.length', $script);
        self::assertMatchesRegularExpression('#nodes\b.*\bmap\(#s', $script);

        // C2 preserved: no external reference / no fetch in the whole document
        self::assertDoesNotMatchRegularExpression('#https?://#', $html);
        self::assertStringNotContainsString('fetch(', $html);
    }
}
