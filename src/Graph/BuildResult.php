<?php

declare(strict_types=1);

namespace ClassViewer\Graph;

final class BuildResult
{
    /**
     * @param array{version: int, nodes: list<array{id: string, name: string, ns: string, kind: string, file: string, external?: true}>, edges: list<array{from: string, to: string}>} $graph
     * @param list<string>                                                                                                                   $warnings
     */
    public function __construct(
        public readonly array $graph,
        public readonly array $warnings,
    ) {
    }
}
