<?php declare(strict_types=1);

use Rector\Config\RectorConfig;

if (! defined('SMF')) {
	define('SMF', 'rector');
}

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src/Sources',
	])
	->withSkip([
		__DIR__ . '**/vendor/*',
	])
	->withParallel(360)
	->withIndent(indentChar: "\t")
	->withImportNames(importShortClasses: false, removeUnusedImports: true)
	->withPreparedSets(deadCode: true)
	->withPhpSets();
