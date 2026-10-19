<?php

declare(strict_types=1);

namespace ClassViewer\Command;

use ClassViewer\Graph\GraphBuilder;
use ClassViewer\Html\HtmlRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scan', description: 'Generate a self-contained graph.html of a target project.')]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly GraphBuilder $builder = new GraphBuilder(),
        private readonly HtmlRenderer $renderer = new HtmlRenderer(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the target PHP project directory.');
        $this->addOption(
            'include-vendor',
            null,
            InputOption::VALUE_NONE,
            'Emit referenced out-of-project classes as external nodes.'
        );
        $this->addOption(
            'json',
            null,
            InputOption::VALUE_NONE,
            'Print the pretty graph.json on stdout instead of writing graph.html.'
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Path of the generated HTML file (default: ./graph.html).'
        );
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

        $result = $this->builder->build($path, true === $input->getOption('include-vendor'));

        foreach ($result->warnings as $warning) {
            $errorStyle->writeln(sprintf('<comment>%s</comment>', $warning));
        }

        if (true === $input->getOption('json')) {
            $encoded = json_encode($result->graph, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            // Cast required by PHPStan (string|false); JSON_THROW_ON_ERROR guarantees a string or an exception.
            // @infection-ignore-all
            $output->writeln((string) $encoded);

            return Command::SUCCESS;
        }

        /** @var string|null $outputPath */
        $outputPath = $input->getOption('output');
        $outputPath ??= 'graph.html';

        $directory = \dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($outputPath, $this->renderer->render($result->graph));

        return Command::SUCCESS;
    }
}
