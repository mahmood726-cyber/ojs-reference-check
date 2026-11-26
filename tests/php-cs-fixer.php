<?php

// PKP's own coding style, read from an unpacked OJS release (set OJS_DIR)
$ojs = getenv('OJS_DIR') ?: exit("Set OJS_DIR\n");
require "{$ojs}/lib/pkp/classes/dev/fixers/bootstrap.php";

$finder = PhpCsFixer\Finder::create()->in(dirname(__DIR__))->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules(include "{$ojs}/lib/pkp/.php_cs_rules")
    ->registerCustomFixers(new PKP\dev\fixers\Fixers())
    ->setFinder($finder);
