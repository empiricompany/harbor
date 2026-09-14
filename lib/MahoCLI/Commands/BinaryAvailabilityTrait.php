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

trait BinaryAvailabilityTrait
{
    /**
     * Whether a binary is available in PATH, checked with the POSIX
     * `command -v` builtin (no dependency on `which`).
     */
    protected function binaryExists(string $binary): bool
    {
        $cmd = sprintf('command -v %s >/dev/null 2>&1', escapeshellarg($binary));
        exec($cmd, $out, $exitCode);
        return $exitCode === 0;
    }
}
