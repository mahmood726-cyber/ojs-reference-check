<?php

/**
 * Check the plugin against the code of an unpacked OJS release, without a
 * database or an installed site. This is NOT a test inside a running OJS.
 *
 *     php tests/ojs-compat.php /path/to/ojs
 *
 * The plugin package must already be unpacked into
 * plugins/generic/referenceCheck of that OJS directory. Needs a network.
 */

$ojs = realpath($argv[1] ?? '') ?: exit("Usage: php tests/ojs-compat.php /path/to/ojs\n");
$plugin = "{$ojs}/plugins/generic/referenceCheck";
is_dir($plugin) || exit("Plugin not found in {$plugin}\n");

error_reporting(E_ALL & ~E_DEPRECATED);
define('PKP_STRICT_MODE', true);
define('INDEX_FILE_LOCATION', "{$ojs}/index.php");
define('BASE_SYS_DIR', $ojs);
// OJS includes some files relative to its own directory
chdir($ojs);
require "{$ojs}/lib/pkp/lib/vendor/autoload.php";

use APP\plugins\generic\referenceCheck\classes\DoiLookup;
use APP\plugins\generic\referenceCheck\classes\OjsHttpFetcher;
use APP\plugins\generic\referenceCheck\classes\ReferenceChecker;
use APP\plugins\generic\referenceCheck\ReferenceCheckController;
use APP\plugins\generic\referenceCheck\ReferenceCheckPlugin;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use PKP\core\APIRouter;

$failed = 0;
function check(string $what, bool $ok): void
{
    global $failed;
    $failed += $ok ? 0 : 1;
    echo $ok ? 'ok   ' : 'FAIL ', $what, "\n";
}

// Declaring these classes verifies their signatures against the OJS base classes
check('plugin class loads', class_exists(ReferenceCheckPlugin::class));
check('controller class loads', class_exists(ReferenceCheckController::class));

// Every class the plugin imports must exist in this release
$files = array_merge(glob("{$plugin}/*.php"), glob("{$plugin}/classes/*.php"));
foreach ($files as $file) {
    preg_match_all('/^use ((?:APP|PKP|Illuminate|GuzzleHttp)\\\\[\w\\\\]+);/m', file_get_contents($file), $imports);
    foreach ($imports[1] as $class) {
        if (!class_exists($class) && !interface_exists($class)) {
            check(basename($file) . " imports missing {$class}", false);
        }
    }
}
check('imports resolved', $failed === 0);

$controller = new ReferenceCheckController();
check('role middleware', in_array('has.roles:1|16|17|4097', $controller->getRouteGroupMiddleware(), true));

// The OJS rule that decides whether a request belongs to this controller
$matches = new ReflectionMethod(APIRouter::class, 'matchesPluginHandlerPath');
$path = '/index.php/journal/api/v1/referenceCheck/5/publications/7';
check('API path matches', $matches->invoke(new APIRouter(), $path, $controller->getHandlerPath()));
check('other API paths do not', !$matches->invoke(new APIRouter(), '/index.php/journal/api/v1/submissions/5', 'referenceCheck'));

// The route itself, in the router OJS uses
$container = new Container();
$router = new Router(new Dispatcher($container), $container);
$container->instance('router', $router);
Facade::setFacadeApplication($container);
Route::group(['prefix' => 'referenceCheck'], $controller->getGroupRoutes(...));
$route = $router->getRoutes()->match(Request::create('/referenceCheck/5/publications/7', 'POST'));
check('POST route', $route->parameters() === ['submissionId' => '5', 'publicationId' => '7']);
try {
    $router->getRoutes()->match(Request::create('/referenceCheck/5/publications/7', 'GET'));
    check('GET refused', false);
} catch (Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
    check('GET refused', true);
}

// The locale file, read by the library OJS uses
$translations = (new Gettext\Loader\PoLoader())->loadFile("{$plugin}/locale/en/locale.po");
$ids = [];
foreach ($translations as $translation) {
    if ($translation->getTranslation()) {
        $ids[] = $translation->getOriginal();
    }
}
$uiKeys = json_decode(file_get_contents("{$plugin}/registry/uiLocaleKeysBackend.json"), true);
check('interface translation keys', is_array($uiKeys) && !array_diff($uiKeys, $ids));

// A real lookup through the HTTP client shipped with OJS
$report = (new ReferenceChecker(new DoiLookup(new OjsHttpFetcher(new GuzzleHttp\Client()))))->check([
    'doi:10.1016/S0140-6736(97)11096-0',
    'https://doi.org/10.5281/zenodo.3678325',
    'doi:10.1038/this-does-not-exist-000000',
]);
check(
    'live lookup through Guzzle',
    array_column($report['references'], 'status') === ['retracted', 'not_in_crossref', 'doi_not_found']
);

echo $failed ? "{$failed} checks failed\n" : "all checks passed\n";
exit($failed ? 1 : 0);
