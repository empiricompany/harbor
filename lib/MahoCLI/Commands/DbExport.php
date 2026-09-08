<?php

/**
 * Maho - Local Command
 *
 * @package    MahoCLI
 * @copyright  Copyright (c) 2024-2026 Maho (https://mahocommerce.com)
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace MahoCLI\Commands;

use MahoCLI\Commands\BaseMahoCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'db:export',
    description: 'Export database to SQL dump using credentials from local.xml',
)]
class DbExport extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to output dump file')
            ->addOption('compression', null, InputOption::VALUE_REQUIRED, 'Compression type: gzip or none', 'gzip')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite output file if it already exists');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read credentials directly from local.xml without bootstrapping Mage.
        $localXmlPath = MAHO_ROOT_DIR . '/app/etc/local.xml';
        if (!file_exists($localXmlPath)) {
            $output->writeln("<error>local.xml not found at $localXmlPath</error>");
            return Command::FAILURE;
        }

        $xml = simplexml_load_file($localXmlPath);
        if ($xml === false) {
            $output->writeln('<error>Failed to parse local.xml</error>');
            return Command::FAILURE;
        }

        $conn = $xml->global->resources->default_setup->connection;
        $host = (string) $conn->host;
        $dbname = (string) $conn->dbname;
        $user = (string) $conn->username;
        $password = (string) $conn->password;

        $file = (string) $input->getArgument('file');
        $compression = (string) $input->getOption('compression');

        if (!in_array($compression, ['gzip', 'none'], true)) {
            $output->writeln('<error>Invalid compression. Allowed values: gzip, none</error>');
            return Command::FAILURE;
        }

        if (file_exists($file) && !$input->getOption('force')) {
            $output->writeln("<error>Output file already exists: $file (use --force to overwrite)</error>");
            return Command::FAILURE;
        }

        $targetDir = dirname($file);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $output->writeln("<error>Cannot create directory: $targetDir</error>");
            return Command::FAILURE;
        }

        $dumpBase = sprintf(
            'mysqldump --single-transaction --quick -h %s -u%s -p%s %s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($password),
            escapeshellarg($dbname),
        );

        $sedFilter = 'LANG=C LC_CTYPE=C LC_ALL=C sed -e ' . escapeshellarg('s/DEFINER[ ]*=[ ]*[^*]*\*/\*/');

        $command = match ($compression) {
            'gzip' => sprintf('%s | %s | gzip -c > %s', $dumpBase, $sedFilter, escapeshellarg($file)),
            default => sprintf('%s | %s > %s', $dumpBase, $sedFilter, escapeshellarg($file)),
        };

        $output->writeln("Exporting database <info>$dbname</info> to <info>$file</info>...");

        // Use pipefail so mysqldump failures are not hidden by gzip succeeding.
        $shellCommand = sprintf('/bin/bash -o pipefail -c %s', escapeshellarg($command));
        $lines = [];
        exec($shellCommand . ' 2>&1', $lines, $exitCode);
        $combinedOutput = trim(implode("\n", $lines));
        if ($combinedOutput !== '') {
            $output->writeln($combinedOutput);
        }

        if ($exitCode !== 0) {
            if (file_exists($file)) {
                unlink($file);
            }
            $output->writeln('<error>Database export failed</error>');
            return Command::FAILURE;
        }

        $size = file_exists($file) ? filesize($file) : 0;
        $output->writeln(sprintf('<info>Database exported successfully (%s bytes).</info>', number_format((float) $size, 0, '.', ',')));

        return Command::SUCCESS;
    }
}
