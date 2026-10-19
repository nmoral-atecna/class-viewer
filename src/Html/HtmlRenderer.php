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
            <style>
            body{font-family:sans-serif;margin:1rem}
            svg .node circle{fill:#1f77b4}
            svg .node text{font-size:12px}
            svg .edge{stroke:#555;stroke-width:1.5}
            svg #arrow path{fill:#555}
            </style>
            </head>
            <body>
            <main id="app"></main>
            <script type="application/json" id="graph">{$payload}</script>
            <script>
            (function () {
                var graph = JSON.parse(document.getElementById('graph').textContent);
                var nodes = graph.nodes || [];
                var edges = graph.edges || [];
                var app = document.getElementById('app');

                if (nodes.length === 0) {
                    app.textContent = 'aucun nœud';
                    return;
                }

                function esc(value) {
                    return String(value)
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }

                var byId = {};
                nodes.forEach(function (node) { byId[node.id] = node; });

                // Degree (incoming + outgoing) per node, to pick the initial focus.
                var degrees = {};
                nodes.forEach(function (node) { degrees[node.id] = 0; });
                edges.forEach(function (edge) {
                    if (edge.from in degrees) { degrees[edge.from]++; }
                    if (edge.to in degrees) { degrees[edge.to]++; }
                });

                // Initial focus = most connected; ties broken by the smallest id.
                var focus = nodes[0];
                nodes.forEach(function (node) {
                    if (degrees[node.id] > degrees[focus.id] ||
                        (degrees[node.id] === degrees[focus.id] && node.id < focus.id)) {
                        focus = node;
                    }
                });

                var width = 960, height = 640, cx = width / 2, cy = height / 2, radius = 220;

                function nodeMarkup(node, x, y, detailed) {
                    // Focus: FQCN plus namespace. Neighbour: short name only.
                    var text = detailed
                        ? node.id + (node.ns ? ' (' + node.ns + ')' : '')
                        : (node.name || node.id);
                    return '<g class="node" data-id="' + esc(node.id) + '">' +
                        '<circle cx="' + x + '" cy="' + y + '" r="' + (detailed ? 10 : 6) + '"></circle>' +
                        '<text x="' + (x + 12) + '" y="' + (y + 4) + '">' + esc(text) + '</text>' +
                        '</g>';
                }

                // Rebuild and repaint the ego-graph around `focus`. Called once at load
                // and again on every click, so a click re-centres without any navigation.
                function draw(focus) {
                    // Direct neighbours only, each with the arrow sense from the focus.
                    var neighbours = [];
                    var seen = {};
                    edges.forEach(function (edge) {
                        var other = null;
                        var outgoing = false;
                        if (edge.from === focus.id && edge.to !== focus.id) { other = edge.to; outgoing = true; }
                        else if (edge.to === focus.id && edge.from !== focus.id) { other = edge.from; outgoing = false; }
                        if (other === null || !(other in byId) || other in seen) { return; }
                        seen[other] = true;
                        neighbours.push({ node: byId[other], outgoing: outgoing });
                    });

                    // Deterministic initial placement: radial around the focus, seeds the force engine.
                    neighbours.forEach(function (entry, i) {
                        var angle = (2 * Math.PI * i) / neighbours.length;
                        entry.x = cx + radius * Math.cos(angle);
                        entry.y = cy + radius * Math.sin(angle);
                    });

                    // Homemade force engine: bounded, deterministic relaxation of the neighbours.
                    // Focus is pinned at the centre; three forces act on each neighbour:
                    // repulsion between neighbours, spring toward the focus along its edge, centring.
                    var REPULSION = 90000, SPRING = 0.02, CENTERING = 0.01;
                    for (var step = 0; step < 300; step++) {
                        for (var a = 0; a < neighbours.length; a++) {
                            var na = neighbours[a], fx = 0, fy = 0;
                            // Repulsion from every other neighbour.
                            for (var b = 0; b < neighbours.length; b++) {
                                if (a === b) { continue; }
                                var nb = neighbours[b];
                                var dx = na.x - nb.x, dy = na.y - nb.y;
                                var d2 = dx * dx + dy * dy || 0.01;
                                var f = REPULSION / d2;
                                var d = Math.sqrt(d2);
                                fx += (dx / d) * f;
                                fy += (dy / d) * f;
                            }
                            // Spring toward the focus (edge rest length = radius).
                            var sx = cx - na.x, sy = cy - na.y;
                            var sd = Math.sqrt(sx * sx + sy * sy) || 0.01;
                            var pull = SPRING * (sd - radius);
                            fx += (sx / sd) * pull * sd;
                            fy += (sy / sd) * pull * sd;
                            // Centring toward the canvas centre.
                            fx += (cx - na.x) * CENTERING;
                            fy += (cy - na.y) * CENTERING;
                            na.x += fx * 0.01;
                            na.y += fy * 0.01;
                        }
                    }

                    var markup = '';
                    neighbours.forEach(function (entry) {
                        // Outgoing: focus -> neighbour; incoming: neighbour -> focus. Same marker, opposite ends.
                        var x1 = entry.outgoing ? cx : entry.x, y1 = entry.outgoing ? cy : entry.y;
                        var x2 = entry.outgoing ? entry.x : cx, y2 = entry.outgoing ? entry.y : cy;
                        markup += '<line class="edge ' + (entry.outgoing ? 'out' : 'in') + '"' +
                            ' x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '"' +
                            ' marker-end="url(#arrow)"></line>';
                    });
                    neighbours.forEach(function (entry) {
                        markup += nodeMarkup(entry.node, entry.x, entry.y, false);
                    });
                    markup += nodeMarkup(focus, cx, cy, true);

                    app.innerHTML = '<svg width="' + width + '" height="' + height + '"' +
                        ' viewBox="0 0 ' + width + ' ' + height + '">' +
                        '<defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5"' +
                        ' markerWidth="8" markerHeight="8" orient="auto-start-reverse">' +
                        '<path d="M0,0 L10,5 L0,10 z"></path></marker></defs>' +
                        markup + '</svg>';
                }

                // Click to re-centre: redraw around the clicked node, in place, no navigation.
                // Clicking the current focus is a strict no-op.
                var current = focus;
                app.addEventListener('click', function (event) {
                    var g = event.target.closest('g.node');
                    if (!g) { return; }
                    var clicked = byId[g.getAttribute('data-id')];
                    if (!clicked || clicked.id === current.id) { return; }
                    current = clicked;
                    draw(current);
                });

                draw(focus);
            })();
            </script>
            </body>
            </html>

            HTML;
    }
}
