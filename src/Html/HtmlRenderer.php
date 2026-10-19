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
            svg .node.far circle{opacity:.3;pointer-events:none}
            svg .node text{font-size:12px}
            #controls{margin-bottom:.5rem}
            svg .edge{stroke:#555;stroke-width:1.5}
            svg #arrow path{fill:#555}
            #search-box{margin-bottom:.5rem;position:relative;display:inline-block}
            #suggestions{list-style:none;margin:0;padding:0;border:1px solid #ccc;
                position:absolute;background:#fff;min-width:16rem;max-height:12rem;overflow:auto}
            #suggestions:empty{display:none}
            #suggestions li{padding:.2rem .5rem;cursor:pointer}
            #suggestions li:hover{background:#eee}
            </style>
            </head>
            <body>
            <div id="search-box">
            <input id="search" type="text" placeholder="rechercher une classe" autocomplete="off">
            <ul id="suggestions"></ul>
            </div>
            <div id="controls">
            <label for="radius">rayon</label>
            <input id="radius" type="range" min="1" max="3" value="1">
            </div>
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

                // Undirected adjacency (both edge senses), built once; drives the BFS by radius.
                var adjacency = {};
                function link(a, b) {
                    if (!(a in byId) || !(b in byId) || a === b) { return; }
                    (adjacency[a] = adjacency[a] || []).push(b);
                }
                edges.forEach(function (edge) { link(edge.from, edge.to); link(edge.to, edge.from); });

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

                function nodeMarkup(node, x, y, kind) {
                    // focus: FQCN + namespace, detailed. near: short name, clickable.
                    // far (distance >= 2): a dim point, no label, not clickable.
                    if (kind === 'far') {
                        return '<g class="node far" data-id="' + esc(node.id) + '">' +
                            '<circle cx="' + x + '" cy="' + y + '" r="4"></circle></g>';
                    }
                    var detailed = kind === 'focus';
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
                    var maxRadius = parseInt(document.getElementById('radius').value, 10) || 1;

                    // Undirected BFS from the focus, bounded by the radius slider.
                    var dist = {};
                    dist[focus.id] = 0;
                    var queue = [focus.id];
                    while (queue.length) {
                        var id = queue.shift();
                        if (dist[id] >= maxRadius) { continue; }
                        (adjacency[id] || []).forEach(function (other) {
                            if (other in dist) { return; }
                            dist[other] = dist[id] + 1;
                            queue.push(other);
                        });
                    }

                    // Distance 1 = clickable neighbours, laid out by the force engine.
                    // Outgoing/incoming is only meaningful for direct edges with the focus.
                    var neighbours = [];
                    var seen = {};
                    (adjacency[focus.id] || []).forEach(function (other) {
                        if (dist[other] !== 1 || other in seen) { return; }
                        seen[other] = true;
                        var outgoing = edges.some(function (e) { return e.from === focus.id && e.to === other; });
                        neighbours.push({ node: byId[other], outgoing: outgoing });
                    });

                    // Distance >= 2 = dim points, no label, not clickable.
                    var far = [];
                    Object.keys(dist).forEach(function (id) { if (dist[id] >= 2) { far.push(byId[id]); } });

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
                        markup += nodeMarkup(entry.node, entry.x, entry.y, 'near');
                    });
                    // Far points sit on an outer ring; no edges are drawn to them (J1 scope).
                    far.forEach(function (node, i) {
                        var angle = (2 * Math.PI * i) / far.length;
                        markup += nodeMarkup(node, cx + (radius + 120) * Math.cos(angle),
                            cy + (radius + 120) * Math.sin(angle), 'far');
                    });
                    markup += nodeMarkup(focus, cx, cy, 'focus');

                    app.innerHTML = '<svg width="' + width + '" height="' + height + '"' +
                        ' viewBox="0 0 ' + width + ' ' + height + '">' +
                        '<defs><marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5"' +
                        ' markerWidth="8" markerHeight="8" orient="auto-start-reverse">' +
                        '<path d="M0,0 L10,5 L0,10 z"></path></marker></defs>' +
                        markup + '</svg>';
                }

                // Re-centre: redraw around a node, in place, no navigation.
                // Re-centring on the current focus is a strict no-op.
                var current = focus;
                function recentre(node) {
                    if (!node || node.id === current.id) { return; }
                    current = node;
                    draw(current);
                }

                app.addEventListener('click', function (event) {
                    var g = event.target.closest('g.node');
                    // Far points (distance >= 2) are not clickable: they never re-centre.
                    if (!g || g.classList.contains('far')) { return; }
                    recentre(byId[g.getAttribute('data-id')]);
                });

                // Changing the radius re-draws the current ego-graph in place.
                document.getElementById('radius').addEventListener('input', function () {
                    draw(current);
                });

                // Homemade search box: substring match, case-insensitive, on short name or
                // FQCN; external/vendor nodes are excluded. No match = silent empty list.
                var search = document.getElementById('search');
                var suggestions = document.getElementById('suggestions');
                search.addEventListener('input', function () {
                    var query = search.value.toLowerCase();
                    suggestions.innerHTML = '';
                    if (query === '') { return; }
                    nodes.forEach(function (node) {
                        if (node.external) { return; }
                        var name = (node.name || '').toLowerCase();
                        if (node.id.toLowerCase().indexOf(query) === -1 &&
                            name.indexOf(query) === -1) { return; }
                        var li = document.createElement('li');
                        li.setAttribute('data-id', node.id);
                        li.textContent = node.id;
                        suggestions.appendChild(li);
                    });
                });
                suggestions.addEventListener('click', function (event) {
                    var li = event.target.closest('li');
                    if (!li) { return; }
                    recentre(byId[li.getAttribute('data-id')]);
                });

                draw(focus);
            })();
            </script>
            </body>
            </html>

            HTML;
    }
}
