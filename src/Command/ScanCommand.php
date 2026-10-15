<?php

declare(strict_types=1);

namespace ClassViewer\Command;

use ClassViewer\Graph\GraphBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scan', description: 'Extract the class graph of a target project as JSON.')]
final class ScanCommand extends Command
{
    public function __construct(private readonly GraphBuilder $builder = new GraphBuilder())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the target PHP project directory.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $path */
        $path = $input->getArgument('path');

        $errorStyle = (new SymfonyStyle($input, $output))->getErrorStyle();

        if (!is_dir($path)) {
            $reason = file_exists($path) ? "n'est pas un répertoire" : 'est introuvable';
            $errorStyle->writeln(sprintf('<error>Le chemin "%s" %s.</error>', $path, $reason));

            return Command::FAILURE;
        }

        $result = $this->builder->build($path);

        foreach ($result->warnings as $warning) {
            $errorStyle->writeln(sprintf('<comment>%s</comment>', $warning));
        }

        $encoded = json_encode($result->graph, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        // Cast required by PHPStan (string|false); JSON_THROW_ON_ERROR guarantees a string or an exception.
        // @infection-ignore-all
        $output->writeln((string) $encoded);

        return Command::SUCCESS;
    }
}
