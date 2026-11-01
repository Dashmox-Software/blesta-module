<?php

/**
 * The Blesta module, against stubs of the framework it expects.
 *
 * Blesta is not on this machine and a module cannot be unit tested without the
 * thing it extends, so the classes it needs are declared here. That is the same
 * trick the FOSSBilling test uses and it has the same limit: this proves the
 * module's own reasoning, not that Blesta calls it the way these stubs do.
 *
 * What is worth testing is the part that is this module's opinion rather than
 * Blesta's: which keys it refuses before opening a socket, which panel ids it
 * reads back off a service, and what a refusal says. Run with:
 *
 *     php tests/blesta_test.php
 */

// The framework, as much of it as this module touches.
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

class Module
{
    public $base_uri = '/';
    // Declared because Blesta's Loader sets them on the module from outside.
    // PHP 8.4 deprecates a dynamic property, and eleven deprecation lines in
    // the output of a suite is how a real notice goes unread.
    public $Input;
    public $Form;
    public $Html;
    protected $view;
    protected $module_row;

    protected function loadConfig($path) {}
    protected function getModuleRow() { return $this->module_row; }
    protected function fields($vars) { return $vars; }
    public function setModuleRow($row) { $this->module_row = $row; }
}

class ModuleFields
{
    public array $fields = [];
    public function label($name, $for = null) { return new ModuleField($name); }
    public function fieldText($name, $value = null, $attrs = []) { return new ModuleField($name); }
    public function fieldPassword($name, $attrs = []) { return new ModuleField($name); }
    public function fieldSelect($name, $options = [], $value = null, $attrs = []) { return new ModuleField($name); }
    public function setField($field) { $this->fields[] = $field; }
}

class ModuleField
{
    public function __construct(public string $name) {}
    public function attach($field) { return $this; }
}

class Language
{
    public static function _($key, $return = false) { return $key; }
    public static function loadLang($file, $language = null, $dir = null) {}
}

class Loader
{
    public static function loadComponents($object, array $components) {
        foreach ($components as $one) { $object->$one = new InputStub(); }
    }
    public static function loadHelpers($object, array $helpers) {
        foreach ($helpers as $one) { $object->$one = new HelperStub(); }
    }
    public static function loadModels($object, array $models) {}
}

class InputStub
{
    private array $rules = [];
    private array $errors = [];
    public function setRules(array $rules) { $this->rules = $rules; }
    public function validates(&$vars)
    {
        foreach ($this->rules as $field => $checks) {
            foreach ($checks as $name => $check) {
                $value = $vars[$field] ?? null;
                $rule = $check['rule'];
                $passed = true;
                if (is_array($rule) && is_callable($rule[0])) {
                    $passed = call_user_func($rule[0], $value);
                } elseif (is_array($rule) && $rule[0] === 'isEmpty') {
                    $passed = $value === null || $value === '';
                    if (!empty($check['negate'])) { $passed = !$passed; }
                } elseif (is_array($rule) && $rule[0] === 'matches') {
                    $passed = is_string($value) && preg_match($rule[1], $value) === 1;
                }
                if (!$passed) { $this->errors[$field][$name] = $check['message']; }
            }
        }
        return empty($this->errors);
    }
    public function setErrors(array $errors) { $this->errors = $errors; }
    public function errors() { return $this->errors; }
}

class HelperStub
{
    public function ifSet(&$value, $default = null) { return $value ?? $default; }
    public function _($value, $return = false) { return $value; }
}

class View
{
    public $base_uri;
    public function __construct($file = null, $view = null) {}
    public function setDefaultView($path) {}
    public function set($name, $value) {}
    public function fetch() { return ''; }
}

require_once __DIR__ . '/../components/modules/dashmox/dashmox.php';

$failures = 0;
$ran = 0;

function check($what, $got, $wanted)
{
    global $failures, $ran;
    $ran++;
    if ($got === $wanted) {
        return;
    }
    $failures++;
    echo "FAIL: $what\n";
    echo '  wanted: ' . var_export($wanted, true) . "\n";
    echo '  got:    ' . var_export($got, true) . "\n";
}

$module = new Dashmox();

// -----------------------------------------------------------------------------
// A key is judged before a socket is opened.
//
// Blesta asks a module to validate its own server row, which is the moment to
// refuse a key that cannot work. The same key accepted here fails during
// provisioning instead, where the error reaches a customer who has just paid.

check('a Dashmox key is accepted', $module->validateKey('dashmox_abc123'), true);
check('a key with no prefix is refused', $module->validateKey('abc123'), false);
check('an empty key is refused', $module->validateKey(''), false);
check('a key that is not a string is refused', $module->validateKey(null), false);
// A WHMCS-style hash pasted into the wrong field is the mistake this catches.
check('somebody else\'s credential is refused', $module->validateKey('some_other_systems_token'), false);

// -----------------------------------------------------------------------------
// The server row is refused when it cannot work.

$vars = ['host' => 'cp.example.com', 'port' => '8443', 'key' => 'dashmox_abc123'];
$meta = $module->addModuleRow($vars);
check('a complete row is accepted', is_array($meta), true);
$stored = [];
foreach ((array) $meta as $pair) {
    $stored[$pair['key']] = $pair;
}
check('the host is stored', $stored['host']['value'], 'cp.example.com');
// The key is a credential and is stored as one. A row that keeps it in the
// clear is a row anybody with database access can use against the panel.
check('the key is stored encrypted', $stored['key']['encrypted'], 1);
check('the host is not', $stored['host']['encrypted'], 0);

$module = new Dashmox();
$bad = ['host' => '', 'port' => '8443', 'key' => 'nonsense'];
check('a row with no host and a bad key is refused', $module->addModuleRow($bad), null);

// -----------------------------------------------------------------------------
// The panel ids are read off the service, not searched for by domain.
//
// A domain can be renamed, moved, or exist twice across customers. A module
// that finds a website by domain will one day find the wrong one, and a
// termination that found the wrong website is not recoverable.

$service = new stdClass();
$service->fields = [
    (object) ['key' => 'dashmox_domain', 'value' => 'one.example'],
    (object) ['key' => 'dashmox_customer_id', 'value' => 'cus_1'],
    (object) ['key' => 'dashmox_site_id', 'value' => 'site_1'],
    (object) ['key' => 'something_else', 'value' => 'ignored'],
];
$read = (new ReflectionClass('Dashmox'))->getMethod('recall');
$read->setAccessible(true);
$ids = $read->invoke(new Dashmox(), $service);
check('the customer id is read back', $ids['customer_id'], 'cus_1');
check('the website id is read back', $ids['site_id'], 'site_1');
check('the domain is read back', $ids['domain'], 'one.example');

// A service with nothing recorded returns empty rather than guessing, so the
// operations that address a website do nothing rather than the wrong thing.
$empty = new stdClass();
$empty->fields = [];
$none = $read->invoke(new Dashmox(), $empty);
check('a service with no ids returns nothing', $none['customer_id'], '');
check('and no website', $none['site_id'], '');

// -----------------------------------------------------------------------------
// A figure that was not measured is null, not zero.
//
// Reporting "used nothing" while the thing that measures it is broken is a
// different claim from "not measured", and only one of them is true.

$measured = (new ReflectionClass('Dashmox'))->getMethod('measured');
$measured->setAccessible(true);
$subject = new Dashmox();
check('a figure is returned', $measured->invoke($subject, fn () => 42), 42);
check('a failure reads as not measured', $measured->invoke($subject, function () {
    throw new RuntimeException('the panel is down');
}), null);
check('a real zero is still zero', $measured->invoke($subject, fn () => 0), 0);

// -----------------------------------------------------------------------------
// The sign on, which is the only way most customers can get in.
//
// A website sold through billing has no password anybody knows: the panel
// customer and the website's own account are both created without one. So this
// button is the credential, and what it does when there is no link to be had
// matters as much as what it does when there is.

class TestableDashmox extends Dashmox
{
    /** Canned answers, taken in order, and a record of what was asked. */
    public array $replies = [];
    public array $asked = [];
    public array $sent = [];

    protected function transport()
    {
        return function ($method, $url, $body, $headers) {
            $this->asked[] = ['method' => $method, 'url' => $url, 'body' => (string) $body];
            $reply = array_shift($this->replies);

            // A request this test did not plan for is a failure of the test,
            // not a panel refusal, and it should read like one.
            return $reply ? $reply : ['status' => 599, 'body' => '{"error":"no canned answer"}', 'headers' => []];
        };
    }

    /** Recorded rather than sent: a die() here would take the suite with it. */
    protected function redirectTo($url)
    {
        $this->sent[] = $url;
    }
}

function signOnSubject(array $replies)
{
    $module = new TestableDashmox();
    $module->replies = $replies;
    $row = new stdClass();
    $row->meta = (object) ['host' => 'cp.example.com', 'port' => '8443', 'key' => 'dashmox_abc123'];
    $module->setModuleRow($row);

    $service = new stdClass();
    $service->id = 77;
    $service->client_id = 12;
    $service->fields = [
        (object) ['key' => 'dashmox_domain', 'value' => 'one.example'],
        (object) ['key' => 'dashmox_site_id', 'value' => 'site_1'],
    ];

    return [$module, $service];
}

$minted = ['status' => 201, 'body' => '{"url":"https://cp.example.com:8443/api/v1/sign-on?token=abc"}', 'headers' => []];

// Pressing the button is what mints the link, and the tab is where the press
// arrives. Blesta hands a module the post, so an empty one means somebody is
// only looking at the page.
list($module, $service) = signOnSubject([$minted]);
$module->tabClientHosting(new stdClass(), $service, [], ['sign_on' => '1']);
check('pressing the button signs the customer in',
    $module->sent, ['https://cp.example.com:8443/api/v1/sign-on?token=abc']);
check('the link is asked of their own website',
    $module->asked[0]['url'], 'https://cp.example.com:8443/api/v1/sites/site_1/sign-on');
// The panel cannot check this, so it is written down as the claim it is. An
// operator asking who went in deserves better than "the billing system".
check('and the panel is told who asked for it',
    strpos($module->asked[0]['body'], 'Blesta, service 77, client 12') !== false, true);

list($looking) = signOnSubject([$minted]);
$looking->tabClientHosting(new stdClass(), $service, [], []);
check('nothing is minted by looking at the page', $looking->sent, []);
$requested = false;
foreach ($looking->asked as $one) {
    if (strpos($one['url'], '/sign-on') !== false) { $requested = true; }
}
check('and no link is asked for either', $requested, false);

// The three refusals the customer can do nothing about. Each lands them on the
// panel's sign-in page, which is where the old link went, rather than on an
// error: an older panel, a key without users.manage, and a website still being
// built. The last one clears on its own.
$signOn = (new ReflectionClass('Dashmox'))->getMethod('signOn');
$signOn->setAccessible(true);
$ids = ['domain' => 'one.example', 'customer_id' => '', 'site_id' => 'site_1'];

foreach ([
    'a panel older than the feature' => 404,
    'a key without users.manage' => 403,
    'a website still being built' => 409,
] as $what => $status) {
    list($refused, $service) = signOnSubject([
        ['status' => $status, 'body' => '{"error":"no"}', 'headers' => []],
    ]);
    $signOn->invoke($refused, $service, $ids);
    check($what . ' lands on the sign-in page', $refused->sent, ['https://cp.example.com:8443/']);
}

// Anything else is worth somebody seeing, so it is reported and the customer is
// sent nowhere. A refusal swallowed into a redirect reads to a customer as a
// panel that lost their password.
list($broken, $service) = signOnSubject([
    ['status' => 500, 'body' => '{"error":"the panel fell over"}', 'headers' => []],
]);
$signOn->invoke($broken, $service, $ids);
check('any other refusal is reported instead', $broken->sent, []);
check('and it says what the panel said',
    $broken->Input->errors(), ['api' => ['response' => 'the panel fell over']]);

// A service with no website recorded is not asked about. Asking would be a
// request for a link to nothing, and the answer would be the same.
list($unbuilt, $service) = signOnSubject([$minted]);
$signOn->invoke($unbuilt, $service, ['domain' => 'one.example', 'customer_id' => '', 'site_id' => '']);
check('a service with no website asks for no link', $unbuilt->asked, []);
check('and still lands on the sign-in page', $unbuilt->sent, ['https://cp.example.com:8443/']);

echo "\n$ran checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
