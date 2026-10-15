<?php

declare(strict_types=1);

namespace ClassViewer\Graph;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class NodeExtractor
{
    /**
     * @return list<array{id: string, name: string, ns: string, kind: string, file: string}>
     *
     * @throws \PhpParser\Error on invalid syntax
     */
    public function extract(string $code, string $relativeFile): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $collector = new NodeCollectingVisitor($relativeFile);

        $traverser = new NodeTraverser(new NameResolver(), $collector);
        $traverser->traverse($ast);

        return $collector->nodes;
    }
}
