<?php

declare(strict_types=1);

namespace ClassViewer\Graph;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects raw candidate edges (resolved FQCN) for each class reference found in a
 * declaration body, typed parameters/properties/returns and attributes. Heritage
 * ({@see ClassLike::$extends}, implements, trait use) is never visited. Filtering to
 * internal nodes, deduplication and self-reference exclusion are done by GraphBuilder.
 */
final class DependencyCollectingVisitor extends NodeVisitorAbstract
{
    /** @var list<array{from: string, to: string}> */
    public array $edges = [];

    /** @var list<string> */
    private array $classStack = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            $this->classStack[] = $node->namespacedName?->toString() ?? '';

            foreach ($node->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    $this->collectName($attribute->name);
                }
            }

            return null;
        }

        if ($node instanceof Param || $node instanceof Property) {
            $this->collectType($node->type);

            return null;
        }

        if ($node instanceof ClassMethod) {
            $this->collectType($node->returnType);

            return null;
        }

        if (
            $node instanceof New_
            || $node instanceof ClassConstFetch
            || $node instanceof StaticCall
            || $node instanceof StaticPropertyFetch
            || $node instanceof Instanceof_
        ) {
            if ($node->class instanceof Name) {
                $this->collectName($node->class);
            }
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }

    private function collectType(?Node $type): void
    {
        if ($type instanceof Name) {
            $this->collectName($type);

            return;
        }

        if ($type instanceof NullableType) {
            $this->collectType($type->type);

            return;
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $member) {
                $this->collectType($member);
            }

            return;
        }

        // Identifier (native scalar) and other ComplexType carry no class reference here.
        if ($type instanceof ComplexType) {
            return;
        }
    }

    private function collectName(Name $name): void
    {
        if ($name->isSpecialClassName()) {
            return;
        }

        $from = end($this->classStack);
        if (false === $from || '' === $from) {
            return;
        }

        $this->edges[] = ['from' => $from, 'to' => $name->toString()];
    }
}
