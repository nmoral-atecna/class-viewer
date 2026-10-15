<?php

declare(strict_types=1);

namespace ClassViewer\Graph;

use PhpParser\Error;

final class GraphBuilder
{
    public function __construct(
        private readonly NodeExtractor $extractor = new NodeExtractor(),
    ) {
    }

    public function build(string $directory): BuildResult
    {
        $warnings = [];
        /** @var array<string, array{id: string, name: string, ns: string, kind: string, file: string}> $nodes */
        $nodes = [];
        /** @var list<array{from: string, to: string}> $rawEdges */
        $rawEdges = [];

        foreach ($this->discover($directory) as $relativeFile) {
            $code = file_get_contents($directory . '/' . $relativeFile);
            try {
                // Cast required by PHPStan (string|false); the file comes from discover() so it is readable.
                // @infection-ignore-all
                $fileNodes = $this->extractor->extract((string) $code, $relativeFile);
                // @infection-ignore-all
                $rawEdges = array_merge($rawEdges, $this->extractor->extractEdges((string) $code));
            } catch (Error $error) {
                $warnings[] = sprintf('Skipped unparsable file "%s": %s', $relativeFile, $error->getMessage());
                continue;
            }

            foreach ($fileNodes as $node) {
                if (isset($nodes[$node['id']])) {
                    $warnings[] = sprintf(
                        'Duplicate declaration "%s" in "%s" ignored (first seen in "%s").',
                        $node['id'],
                        $node['file'],
                        $nodes[$node['id']]['file']
                    );
                    continue;
                }

                $nodes[$node['id']] = $node;
            }
        }

        ksort($nodes);

        return new BuildResult(
            ['version' => 1, 'nodes' => array_values($nodes), 'edges' => $this->resolveEdges($rawEdges, $nodes)],
            $warnings
        );
    }

    /**
     * @param list<array{from: string, to: string}>                                                       $rawEdges
     * @param array<string, array{id: string, name: string, ns: string, kind: string, file: string}>     $nodes
     *
     * @return list<array{from: string, to: string}>
     */
    private function resolveEdges(array $rawEdges, array $nodes): array
    {
        $unique = [];
        foreach ($rawEdges as $edge) {
            if ($edge['from'] === $edge['to'] || !isset($nodes[$edge['to']])) {
                continue;
            }

            $unique[$edge['from'] . "\0" . $edge['to']] = $edge;
        }

        $edges = array_values($unique);
        usort(
            $edges,
            static fn (array $a, array $b): int => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]
        );

        return $edges;
    }

    /**
     * @return list<string>
     */
    private function discover(string $directory): array
    {
        $directoryIterator = new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS);

        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            static fn (\SplFileInfo $file): bool => !($file->isDir() && 'vendor' === $file->getFilename())
        );

        $prefixLength = strlen(rtrim($directory, '/')) + 1;

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($filter) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = substr($file->getPathname(), $prefixLength);
            }
        }

        sort($files);

        return $files;
    }
}
