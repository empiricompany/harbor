<?php

/**
 * SPDX-FileCopyrightText: 2024-2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: MIT
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
