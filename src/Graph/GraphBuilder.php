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

        foreach ($this->discover($directory) as $relativeFile) {
            $code = file_get_contents($directory . '/' . $relativeFile);
            try {
                // Cast required by PHPStan (string|false); the file comes from discover() so it is readable.
                // @infection-ignore-all
                $fileNodes = $this->extractor->extract((string) $code, $relativeFile);
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
            ['version' => 1, 'nodes' => array_values($nodes), 'edges' => []],
            $warnings
        );
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
