<?php

declare(strict_types=1);

namespace ClassViewer\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scan', description: 'List PHP files of a target project.')]
final class ScanCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the target PHP project directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $path */
        $path = $input->getArgument('path');

        if (!is_dir($path)) {
            $reason = file_exists($path) ? "n'est pas un répertoire" : 'est introuvable';
            (new SymfonyStyle($input, $output))
                ->getErrorStyle()
                ->writeln(sprintf('<error>Le chemin "%s" %s.</error>', $path, $reason));

            return Command::FAILURE;
        }

        foreach ($this->scan($path) as $relativePath) {
            $output->writeln($relativePath);
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function scan(string $directory): array
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
