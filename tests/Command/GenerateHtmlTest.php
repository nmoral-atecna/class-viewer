<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Command;

use ClassViewer\Command\ScanCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateHtmlTest extends TestCase
{
    private const GRAPH = __DIR__ . '/../fixtures/graph';

    private string $workDir;
    private ?string $previousCwd = null;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/cv-html-' . uniqid('', true);
        mkdir($this->workDir, 0o777, true);
        $this->previousCwd = getcwd() ?: null;
        chdir($this->workDir);
    }

    protected function tearDown(): void
    {
        if (null !== $this->previousCwd) {
            chdir($this->previousCwd);
        }
        $this->removeDir($this->workDir);
    }

    #[Test]
    public function writesSingleGraphHtmlIntoCurrentDirectory(): void
    {
        // C1.1
        $tester = $this->exec(self::GRAPH . '/edges/nominal');

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($this->workDir . '/graph.html');
    }

    #[Test]
    public function doesNotWriteIntoTargetProject(): void
    {
        // C1.1: fixture cible inchangée
        $target = self::GRAPH . '/edges/nominal';
        $before = $this->snapshot($target);

        $this->exec($target);

        self::assertSame($before, $this->snapshot($target));
    }

    #[Test]
    public function writesToOutputPathAndNotToCurrentDirectory(): void
    {
        // C1.2
        $outputPath = $this->workDir . '/out/g.html';

        $tester = $this->exec(self::GRAPH . '/edges/nominal', output: $outputPath);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($outputPath);
        self::assertFileDoesNotExist($this->workDir . '/graph.html');
    }

    #[Test]
    public function generatesHtmlForEmptyProject(): void
    {
        // C1.3
        $tester = $this->exec(self::GRAPH . '/empty');

        self::assertSame(0, $tester->getStatusCode());
        $graph = $this->embeddedGraph($this->workDir . '/graph.html');
        self::assertSame([], $graph['nodes']);
        self::assertSame([], $graph['edges']);
    }

    #[Test]
    public function htmlIsSelfContained(): void
    {
        // C2.1
        $this->exec(self::GRAPH . '/edges/nominal');
        $html = (string) file_get_contents($this->workDir . '/graph.html');

        self::assertStringContainsString('<script type="application/json"', $html);
        self::assertDoesNotMatchRegularExpression('#https?://#', $html);
        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('fetch(', $html);
        self::assertDoesNotMatchRegularExpression('#\ssrc=#', $html);
    }

    #[Test]
    public function embeddedJsonMatchesJsonOutput(): void
    {
        // C3.1
        $jsonOutput = trim($this->exec(self::GRAPH . '/edges/nominal', json: true)->getDisplay());

        $this->exec(self::GRAPH . '/edges/nominal');
        $payload = $this->jsonPayload((string) file_get_contents($this->workDir . '/graph.html'));
        $unescaped = str_replace('\\u003C', '<', $payload);

        self::assertSame($jsonOutput, $unescaped);
        self::assertSame(
            json_decode($jsonOutput, true, 512, JSON_THROW_ON_ERROR),
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    #[Test]
    public function jsonFlagPrintsPrettyJsonOnStdoutAndWritesNoFile(): void
    {
        // C4.1
        $tester = $this->exec(self::GRAPH . '/edges/nominal', json: true);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString("\n    ", $tester->getDisplay(), 'JSON must be pretty-printed');
        self::assertFileDoesNotExist($this->workDir . '/graph.html');
    }

    private function exec(string $path, bool $json = false, ?string $output = null): CommandTester
    {
        $application = new Application();
        $application->add(new ScanCommand());
        $application->setAutoExit(false);

        $command = $application->find('scan');
        $tester = new CommandTester($command);

        $input = ['command' => $command->getName(), 'path' => $path];
        if ($json) {
            $input['--json'] = true;
        }
        if (null !== $output) {
            $input['--output'] = $output;
        }

        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddedGraph(string $htmlPath): array
    {
        $payload = $this->jsonPayload((string) file_get_contents($htmlPath));
        // `<` is escaped to < in the payload; json_decode interprets it back.
        /** @var array<string, mixed> $graph */
        $graph = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

        return $graph;
    }

    private function jsonPayload(string $html): string
    {
        self::assertSame(
            1,
            preg_match('#<script type="application/json"[^>]*>(.*)</script>#s', $html, $matches)
        );

        return $matches[1];
    }

    /**
     * @return array<string, int>
     */
    private function snapshot(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $files[$file->getPathname()] = (int) $file->getMTime();
        }
        ksort($files);

        return $files;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
