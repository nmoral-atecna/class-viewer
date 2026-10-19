<?php

declare(strict_types=1);

namespace ClassViewer\Tests\Command;

use ClassViewer\Command\ScanCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanCommandTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';
    private const GRAPH = self::FIXTURES . '/graph';

    #[Test]
    public function printsValidGraphJsonOnStdout(): void
    {
        // C1.1
        $tester = $this->runScan(self::GRAPH . '/nominal');

        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        self::assertStringContainsString("\n    ", $display, 'JSON must be pretty-printed');

        $graph = $this->decode($tester);
        self::assertSame(1, $graph['version']);
        self::assertSame([], $graph['edges']);
        self::assertCount(4, $graph['nodes']);
    }

    #[Test]
    public function printsNodesSortedById(): void
    {
        // C1.2
        $tester = $this->runScan(self::GRAPH . '/nominal');

        $ids = array_column($this->decode($tester)['nodes'], 'id');

        self::assertSame([
            'App\Behavior\Baz',
            'App\Contract\Bar',
            'App\Service\Foo',
            'App\Status',
        ], $ids);
    }

    #[Test]
    public function printsEmptyGraphForProjectWithoutDeclaration(): void
    {
        // C7.1
        $tester = $this->runScan(self::GRAPH . '/empty');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(
            ['version' => 1, 'nodes' => [], 'edges' => []],
            $this->decode($tester)
        );
    }

    #[Test]
    public function warnsOnStderrForUnparsableFileAndStillSucceeds(): void
    {
        // C5.1
        $tester = $this->runScan(self::GRAPH . '/parse_error');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['App\Valid'], array_column($this->decode($tester)['nodes'], 'id'));
        self::assertStringContainsString('src/Broken.php', $tester->getErrorOutput());
    }

    #[Test]
    public function warnsOnStderrForDuplicateFqcn(): void
    {
        // C6.1
        $tester = $this->runScan(self::GRAPH . '/dup');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['App\After', 'App\Dup'], array_column($this->decode($tester)['nodes'], 'id'));
        self::assertStringContainsString('App\Dup', $tester->getErrorOutput());
    }

    #[Test]
    public function emitsExternalNodesWhenIncludeVendorFlagIsSet(): void
    {
        // C2.1, C3.1
        $tester = $this->runScan(self::GRAPH . '/edges/external', true);

        self::assertSame(0, $tester->getStatusCode());
        $graph = $this->decode($tester);
        $byId = array_column($graph['nodes'], null, 'id');

        self::assertArrayHasKey('Psr\Log\LoggerInterface', $byId);
        self::assertTrue($byId['Psr\Log\LoggerInterface']['external']);
        self::assertArrayNotHasKey('external', $byId['App\Foo']);
        self::assertCount(3, $graph['edges']);
    }

    #[Test]
    public function dropsExternalNodesWithoutIncludeVendorFlag(): void
    {
        // C1.1
        $tester = $this->runScan(self::GRAPH . '/edges/external');

        $graph = $this->decode($tester);
        self::assertSame(['App\Foo'], array_column($graph['nodes'], 'id'));
        self::assertSame([], $graph['edges']);
    }

    #[Test]
    public function doesNotWriteIntoTargetDirectory(): void
    {
        // C1: rien écrit dans le projet cible
        $target = self::GRAPH . '/nominal';
        $before = $this->snapshot($target);

        $this->runScan($target);

        self::assertSame($before, $this->snapshot($target));
    }

    #[Test]
    public function failsWhenPathIsAbsent(): void
    {
        $tester = $this->runScan('/does/not/exist');

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertNotSame('', trim($tester->getErrorOutput()));
    }

    #[Test]
    public function failsWhenPathIsAFile(): void
    {
        $tester = $this->runScan(self::GRAPH . '/nominal/src/Status.php');

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString("n'est pas un répertoire", $tester->getErrorOutput());
    }

    #[Test]
    public function failsWhenArgumentMissing(): void
    {
        $application = new Application();
        $application->add(new ScanCommand());
        $application->setDefaultCommand('scan', true);
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);

        $output = new BufferedOutput();
        $statusCode = $application->run(new ArrayInput([]), $output);

        self::assertNotSame(0, $statusCode);
        self::assertStringContainsString('path', $output->fetch());
    }

    /**
     * @return array{version: int, nodes: list<array<string, mixed>>, edges: list<mixed>}
     */
    private function decode(CommandTester $tester): array
    {
        /** @var array{version: int, nodes: list<array<string, mixed>>, edges: list<mixed>} $graph */
        $graph = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        return $graph;
    }

    private function runScan(?string $path, bool $includeVendor = false): CommandTester
    {
        $application = new Application();
        $application->add(new ScanCommand());
        $application->setAutoExit(false);

        $command = $application->find('scan');
        $tester = new CommandTester($command);

        $input = ['command' => $command->getName()];
        if (null !== $path) {
            $input['path'] = $path;
        }
        if ($includeVendor) {
            $input['--include-vendor'] = true;
        }

        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
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
}
