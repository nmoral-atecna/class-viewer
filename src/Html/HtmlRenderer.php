<?php

declare(strict_types=1);

namespace ClassViewer\Html;

final class HtmlRenderer
{
    /**
     * @param array{version: int, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} $graph
     */
    public function render(array $graph): string
    {
        $json = json_encode($graph, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        // Escape `<` as a JSON unicode escape so an embedded `</script>` cannot break the tag.
        $payload = str_replace('<', '\\u003C', $json);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <title>class-viewer</title>
            <style>body{font-family:sans-serif;margin:1rem}</style>
            </head>
            <body>
            <main id="app"></main>
            <script type="application/json" id="graph">{$payload}</script>
            </body>
            </html>

            HTML;
    }
}
