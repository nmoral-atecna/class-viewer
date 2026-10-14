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

    #[Test]
    public function listsPhpFilesRecursivelySortedAlphabetically(): void
    {
        // C1.1
        $tester = $this->runScan(self::FIXTURES . '/nominal');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("src/Foo.php\nsrc/Sub/Bar.php\n", $tester->getDisplay());
    }

    #[Test]
    public function outputsNothingWhenNoPhpFile(): void
    {
        // C1.2
        $tester = $this->runScan(self::FIXTURES . '/no_php');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
    }

    #[Test]
    public function keepsOnlyLowercasePhpExtension(): void
    {
        // C1.3
        $tester = $this->runScan(self::FIXTURES . '/extensions');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("Foo.php\n", $tester->getDisplay());
    }

    #[Test]
    public function doesNotWriteIntoTargetDirectory(): void
    {
        // C1.4
        $target = self::FIXTURES . '/nominal';
        $before = $this->snapshot($target);

        $this->runScan($target);

        self::assertSame($before, $this->snapshot($target));
    }

    #[Test]
    public function excludesVendorDirectoryAtRoot(): void
    {
        // C2.1
        $tester = $this->runScan(self::FIXTURES . '/vendor_root');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("src/Foo.php\n", $tester->getDisplay());
    }

    #[Test]
    public function excludesNestedVendorDirectory(): void
    {
        // C2.2
        $tester = $this->runScan(self::FIXTURES . '/vendor_nested');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("app/Keep.php\n", $tester->getDisplay());
    }

    #[Test]
    public function keepsFileNamedVendorPhp(): void
    {
        // C2.3
        $tester = $this->runScan(self::FIXTURES . '/vendor_file');

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame("src/vendor.php\n", $tester->getDisplay());
    }

    #[Test]
    public function failsWhenPathIsAbsent(): void
    {
        // C3.1
        $tester = $this->runScan('/does/not/exist');

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertNotSame('', trim($tester->getErrorOutput()));
    }

    #[Test]
    public function failsWhenPathIsAFile(): void
    {
        // C3.2
        $tester = $this->runScan(self::FIXTURES . '/nominal/src/Foo.php');

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString("n'est pas un répertoire", $tester->getErrorOutput());
    }

    #[Test]
    public function failsWhenArgumentMissing(): void
    {
        // C3.3
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

    private function runScan(?string $path): CommandTester
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
