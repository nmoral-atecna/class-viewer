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

        self::assertSame(1, preg_match('#<script type="application/json"[^>]*>(.*)</script>#s', $html, $m));
        self::assertStringNotContainsString('</script', $m[1]);
        self::assertStringNotContainsString('<x', $m[1]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($graph, $decoded);
    }
}
