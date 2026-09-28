<?php
/**
 * Boot probe for CompatMapIsNotLoadBearingTest, run as a SUBPROCESS.
 *
 * Defines OWA_DISABLE_COMPAT_BRIDGE before OWA loads -- no aliasing autoloader,
 * no map lookups -- boots it, and drives every factory family OWA resolves its
 * own classes through. Anything that still needed the map fails here.
 *
 * A subprocess because the switch is a process-global define read when
 * owa_compat_aliases.php loads, and the PHPUnit process has booted OWA already.
 *
 * Prints one JSON object: { "checked": {family: count}, "errors": [...] }.
 */

if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] =
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
}
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '203.0.113.10';

define('OWA_DISABLE_COMPAT_BRIDGE', true);

$owa_root = dirname(__DIR__, 2) . '/';
require_once($owa_root . 'owa.php');

$errors  = [];
$checked = [];

$try = function (string $family, string $what, callable $make) use (&$errors, &$checked) {
    $checked[$family] = ($checked[$family] ?? 0) + 1;
    try {
        $obj = $make();
        if (!is_object($obj)) {
            $errors[] = "$family $what: no object";
        } elseif (strncmp(get_class($obj), 'owa_', 4) === 0) {
            $errors[] = "$family $what: resolved to legacy " . get_class($obj);
        }
    } catch (\Throwable $e) {
        $errors[] = "$family $what: " . get_class($e) . ': ' . $e->getMessage();
    }
};

try {
    // Boot registers every module's handlers, filters and implementations.
    new owa(['tracking_mode' => true, 'instance_role' => 'logger']);
} catch (\Throwable $e) {
    echo json_encode(['checked' => [], 'errors' => ['boot: ' . $e->getMessage()]]);
    exit;
}

$s = \OWA\Core\CoreAPI::serviceSingleton();

// Each registration names its implementation class -- base.configurableMetric
// for most -- and its params, as ResultSetManager builds them.
foreach ((array) $s->metrics as $name => $registrations) {
    foreach ((array) $registrations as $registration) {
        $try('metric', $name, fn() => \OWA\Core\CoreAPI::metricFactory(
            $registration['class'], $registration['params'] ?? []));
    }
}

foreach ((array) $s->entities as $name => $dotted) {
    $try('entity', (string) $dotted, fn() => \OWA\Core\CoreAPI::entityFactory($dotted));
}

foreach (['emailAddress', 'entityDoesNotExist', 'entityExists', 'inArray', 'isNotCurrentUser',
          'required', 'stringLength', 'stringMatch', 'subStringMatch', 'subStringPosition',
          'userName'] as $name) {
    $try('validator', $name, fn() => \OWA\Core\CoreAPI::validationFactory($name));
}

foreach (['base.restApi', 'base.users', 'base.adminPage', 'base.mail'] as $view) {
    $try('view', $view, fn() => \OWA\Core\CoreAPI::moduleFactory($view, 'View', []));
}

foreach (['base.userHandlers', 'base.notifyHandlers', 'base.eventRawHandlers'] as $h) {
    [$module, $file] = explode('.', $h);
    $try('handler', $h, fn() => \OWA\Core\CoreAPI::moduleGenericFactory($module, 'handlers', $file));
}
$try('handler', 'maxmind_geoip.maxmind',
    fn() => \OWA\Core\CoreAPI::moduleGenericFactory('maxmind_geoip', 'classes', 'maxmind'));

foreach (['event_queue_types', 'object_cache_types'] as $map) {
    foreach ((array) $s->getMap($map) as $type => $impl) {
        $try('implementation', "$map.$type",
            fn() => \OWA\Core\Lib::simpleFactory($impl[0], $impl[1], $impl[2] ?? []));
    }
}

$try('support', 'base.template', fn() => \OWA\Core\CoreAPI::supportClassFactory('base', 'template'));
$try('support', 'base.siteManager', fn() => \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager'));

// Every registry callback is a callable string that names a real method.
$registry = json_decode((string) file_get_contents(
    $owa_root . 'modules/Base/config/tracking_properties.json'), true);
foreach ($registry as $name => $property) {
    foreach ((array) ($property['callbacks'] ?? []) as $callback) {
        $checked['callback'] = ($checked['callback'] ?? 0) + 1;
        if (!is_callable($callback)) {
            $errors[] = "callback $name: $callback is not callable";
        }
    }
}

echo json_encode(['checked' => $checked, 'errors' => $errors]);
