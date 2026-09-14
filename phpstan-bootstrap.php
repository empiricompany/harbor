<?php

declare(strict_types=1);

// Defined at runtime by the Maho CLI / web entry points (public/index.php,
// public/api.php, public/rest.php, the `maho` script). Harbor commands read it
// without bootstrapping Mage, so PHPStan needs it declared here.
if (!defined('MAHO_ROOT_DIR')) {
    define('MAHO_ROOT_DIR', '/app');
}
