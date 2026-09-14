<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector as CodeQuality;
use Rector\CodingStyle\Rector as CodingStyle;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector as DeadCode;
use Rector\EarlyReturn\Rector as EarlyReturn;
use Rector\TypeDeclaration\Rector as TypeDeclaration;

// Shared by every repo that consumes the org baseline. Only standard Rector
// rules, so it needs no extra dependency: maho's own .rector.php (with the
// Maho\Rector\* rules and the Varien->Maho migration) stays in maho and is not
// synced. Only the paths that exist in a given repo are scanned, so the one
// config works for app-only modules and the infra tool's src/ alike.
//
// Existence alone is not enough: in a module repo `composer install` lets the
// maho composer plugin materialize maho core files under public/ and lib/.
// Those files are never tracked by the module. Some modules git-ignore them,
// some ignore the whole directory, and some leave them untracked next to their
// own tracked skin or js files. Rector must not lint core files a module cannot
// change, so every path git does not track is skipped. Without
// --exclude-standard, ls-files lists ignored files as well as plain untracked
// ones, and --directory collapses a fully untracked directory to one entry.
function gitUntrackedPaths(): array
{
    exec(
        'git -C ' . escapeshellarg(__DIR__) . ' ls-files --others --directory 2>/dev/null',
        $paths,
    );

    return array_map(static fn(string $path): string => __DIR__ . '/' . rtrim($path, '/'), $paths);
}

return RectorConfig::configure()
    ->withPaths(array_values(array_merge(
        array_filter([
            __DIR__ . '/app',
            __DIR__ . '/lib',
            __DIR__ . '/public',
            __DIR__ . '/src',
        ], is_dir(...)),
        // Root-level entry points (e.g. the infra tool's sync.php / config.php).
        // This very config file is included as well, so it must satisfy its
        // own rules.
        glob(__DIR__ . '/*.php') ?: [],
    )))
    // No argument: Rector reads the target PHP version from composer.json
    // (require.php's floor, else config.platform.php), which the sync keeps in
    // step with maho.
    ->withPhpSets()
    ->withSkip([
        ...gitUntrackedPaths(),
        Rector\Php81\Rector\Property\ReadOnlyPropertyRector::class,
        Rector\Php82\Rector\Class_\ReadOnlyClassRector::class,
        Rector\Php83\Rector\ClassConst\AddTypeToConstRector::class,
    ])
    ->withRules([
        CodeQuality\BooleanNot\ReplaceMultipleBooleanNotRector::class,
        CodeQuality\FuncCall\ChangeArrayPushToArrayAssignRector::class,
        CodeQuality\FuncCall\CompactToVariablesRector::class,
        CodeQuality\Identical\SimplifyArraySearchRector::class,
        CodeQuality\Identical\SimplifyConditionsRector::class,
        CodeQuality\Identical\StrlenZeroToIdenticalEmptyStringRector::class,
        CodeQuality\LogicalAnd\LogicalToBooleanRector::class,
        CodeQuality\NotEqual\CommonNotEqualRector::class,
        CodeQuality\Ternary\SimplifyTautologyTernaryRector::class,
        CodingStyle\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector::class,
        DeadCode\ClassMethod\RemoveUselessParamTagRector::class,
        DeadCode\ClassMethod\RemoveUselessReturnTagRector::class,
        DeadCode\MethodCall\RemoveNullArgOnNullDefaultParamRector::class,
        DeadCode\Property\RemoveUselessVarTagRector::class,
        EarlyReturn\If_\RemoveAlwaysElseRector::class,
        Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector::class,
        TypeDeclaration\StmtsAwareInterface\SafeDeclareStrictTypesRector::class,
    ]);
