<?php

// Let static analysis resolve OJS classes from an unpacked release (set OJS_DIR)
$ojs = getenv('OJS_DIR') ?: exit("Set OJS_DIR\n");
define('PKP_STRICT_MODE', true);
define('INDEX_FILE_LOCATION', "{$ojs}/index.php");
define('BASE_SYS_DIR', $ojs);
require "{$ojs}/lib/pkp/lib/vendor/autoload.php";
