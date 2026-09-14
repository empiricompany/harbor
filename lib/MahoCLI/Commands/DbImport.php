<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
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
    name: 'db:import',
    description: 'Import a SQL dump into the database using credentials from local.xml',
)]
class DbImport extends BaseMahoCommand
{
    use BinaryAvailabilityTrait;
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the SQL dump file')
            ->addOption('drop-tables', null, InputOption::VALUE_NONE, 'Drop all tables before importing')
            ->addOption('compression', null, InputOption::VALUE_REQUIRED, 'Compression type: gzip, zstd, or none', 'none');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read credentials directly from local.xml — do NOT call initMaho()
        // because the DB may be empty and Mage would crash trying to query core_website
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

        $conn     = $xml->global->resources->default_setup->connection;
        $host     = (string) $conn->host;
        $dbname   = (string) $conn->dbname;
        $user     = (string) $conn->username;
        $password = (string) $conn->password;

        $file = (string) $input->getArgument('file');
        if (!file_exists($file)) {
            $output->writeln("<error>File not found: $file</error>");
            return Command::FAILURE;
        }

        $mysqlBase = sprintf(
            'mysql -h %s -u%s -p%s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($password),
        );

        if ($input->getOption('drop-tables')) {
            $output->writeln("Dropping and recreating database <info>$dbname</info>...");

            $dropCmd = sprintf(
                '%s -e %s && %s -e %s',
                $mysqlBase,
                escapeshellarg("DROP DATABASE IF EXISTS `$dbname`"),
                $mysqlBase,
                escapeshellarg("CREATE DATABASE `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"),
            );

            passthru($dropCmd, $exitCode);
            if ($exitCode !== 0) {
                $output->writeln('<error>Failed to drop/recreate database</error>');
                return Command::FAILURE;
            }
        }

        $output->writeln("Importing <info>$file</info> into database <info>$dbname</info>...");

        $mysqlImport = sprintf('%s %s', $mysqlBase, escapeshellarg($dbname));
        $compression = (string) $input->getOption('compression');

        if ($compression === 'zstd' && !$this->binaryExists('zstd')) {
            $output->writeln('<error>zstd is required by --compression=zstd but is not installed on the server</error>');
            return Command::FAILURE;
        }

        // `pv` is a soft dependency: when present it shows a bar on the
        // compressed file bytes; otherwise we fall back to `cat`.
        $hasPv  = $this->binaryExists('pv');
        $size   = (int) filesize($file);
        $source = $hasPv ? sprintf('pv -s %d %s', $size, escapeshellarg($file)) : sprintf('cat %s', escapeshellarg($file));

        $importCmd = match ($compression) {
            'gzip'  => sprintf('%s | gunzip -c | %s', $source, $mysqlImport),
            'zstd'  => sprintf('%s | zstd -dc | %s', $source, $mysqlImport),
            default => $hasPv
                ? sprintf('%s | %s', $source, $mysqlImport)
                : sprintf('%s < %s', $mysqlImport, escapeshellarg($file)),
        };

        passthru($importCmd, $exitCode);
        if ($exitCode !== 0) {
            $output->writeln('<error>Database import failed</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Database imported successfully.</info>');
        return Command::SUCCESS;
    }
}
