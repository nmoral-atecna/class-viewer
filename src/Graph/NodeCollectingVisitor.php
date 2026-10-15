<?php

declare(strict_types=1);

namespace ClassViewer\Graph;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

final class NodeCollectingVisitor extends NodeVisitorAbstract
{
    /** @var list<array{id: string, name: string, ns: string, kind: string, file: string}> */
    public array $nodes = [];

    public function __construct(private readonly string $relativeFile)
    {
    }

    public function enterNode(Node $node): null
    {
        if (!$node instanceof ClassLike || null === $node->namespacedName) {
            return null;
        }

        $namespacedName = $node->namespacedName;
        $namespace = $namespacedName->slice(0, -1);

        $this->nodes[] = [
            'id' => $namespacedName->toString(),
            'name' => $namespacedName->getLast(),
            'ns' => $namespace?->toString() ?? '',
            'kind' => $this->kindOf($node),
            'file' => $this->relativeFile,
        ];

        return null;
    }

    private function kindOf(ClassLike $node): string
    {
        if ($node instanceof Interface_) {
            return 'interface';
        }

        if ($node instanceof Enum_) {
            return 'enum';
        }

        if ($node instanceof Trait_) {
            return 'trait';
        }

        return 'class';
    }
}
