<?php

/**
 * Dashmox for Blesta.
 *
 * The third integration, and the one that answers a different question from the
 * first two. WHMCS and FOSSBilling were written to find out whether the panel's
 * API had been shaped around WHMCS; they are shaped nothing alike and the
 * answer was no. Blesta is here because a host who already runs Blesta should
 * not have to change billing systems to run Dashmox.
 *
 * Blesta's module API is a class extending Module with a row of configuration
 * per server, service lifecycle methods that are handed a stdClass of fields,
 * and a set of views. It is closer to FOSSBilling than to WHMCS (objects
 * rather than an array of everything) but it differs in the part that matters
 * here: **Blesta asks a module to validate its own fields**, and a module that
 * accepts a bad one fails later, during provisioning, where the error reaches a
 * customer who has just paid.
 *
 * What it drives is the same client the other two use. There is one piece of
 * code in this repository that talks to the panel, and three thin things on top
 * of it, because three copies of the part that speaks HTTP would agree until
 * the day they did not.
 *
 * Install by copying this directory into Blesta's `components/modules/`.
 */

// The client lives once in this repository and is copied beside each module
// when one is packaged, so a downloaded module is self-contained while there is
// still only one copy to fix. Either location works: its own, if this is an
// unpacked download, or the shared one, if this is the repository.
$dashmoxLib = is_dir(__DIR__ . '/lib') ? __DIR__ . '/lib' : __DIR__ . '/../../../lib';
require_once $dashmoxLib . '/Client.php';
require_once $dashmoxLib . '/Panel.php';

use Dashmox\Whmcs\ApiError;
use Dashmox\Whmcs\Client;
use Dashmox\Whmcs\Panel;

class Dashmox extends Module
{
    /** Prefixes make a leaked key recognisable on sight. */
    const KEY_PREFIX = 'dashmox_';

    public function __construct()
    {
        Loader::loadComponents($this, ['Input']);
        $this->loadConfig(__DIR__ . DS . 'config.json');
        Language::loadLang('dashmox', null, __DIR__ . DS . 'language' . DS);
    }

    public function getName()
    {
        return 'Dashmox';
    }

    public function getAuthors()
    {
        return [['name' => 'Dashmox Software', 'url' => 'https://dashmox.com']];
    }

    /**
     * What a server row holds.
     *
     * The key goes in a field of its own rather than in the password box: it
     * belongs to the installation rather than to a person, and there is no
     * username to go with it.
     */
    public function getAdminAddFields($vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = new ModuleFields();

        $host = $fields->label(Language::_('Dashmox.row_meta.host', true), 'host');
        $host->attach($fields->fieldText('host', $this->Html->ifSet($vars->host), ['id' => 'host']));
        $fields->setField($host);

        $port = $fields->label(Language::_('Dashmox.row_meta.port', true), 'port');
        $port->attach($fields->fieldText('port', $this->Html->ifSet($vars->port, '8443'), ['id' => 'port']));
        $fields->setField($port);

        $key = $fields->label(Language::_('Dashmox.row_meta.key', true), 'key');
        $key->attach($fields->fieldPassword('key', ['id' => 'key', 'value' => $this->Html->ifSet($vars->key)]));
        $fields->setField($key);

        return $fields;
    }

    public function getAdminEditFields($vars = null)
    {
        return $this->getAdminAddFields($vars);
    }

    /**
     * Blesta asks a module to validate its own server row, and this is the
     * moment to refuse a key that cannot work.
     *
     * A key checked here is a mistake somebody fixes while they are looking at
     * the form. The same key accepted here fails during provisioning, where the
     * error reaches a customer who has just paid and an administrator who is
     * not at their desk.
     */
    public function addModuleRow(array &$vars)
    {
        $rules = [
            'host' => [
                'valid' => [
                    'rule' => ['isEmpty'],
                    'negate' => true,
                    'message' => Language::_('Dashmox.!error.host.valid', true),
                ],
            ],
            'key' => [
                'valid' => [
                    'rule' => [[$this, 'validateKey']],
                    'message' => Language::_('Dashmox.!error.key.valid', true),
                ],
            ],
        ];
        $this->Input->setRules($rules);
        if (!$this->Input->validates($vars)) {
            return;
        }

        $meta = [];
        foreach (['host', 'port', 'key'] as $name) {
            $meta[] = ['key' => $name, 'value' => isset($vars[$name]) ? $vars[$name] : '', 'encrypted' => $name === 'key' ? 1 : 0];
        }

        return $meta;
    }

    public function editModuleRow($module_row, array &$vars)
    {
        return $this->addModuleRow($vars);
    }

    /** A Dashmox key is recognisable before a socket is opened. */
    public function validateKey($key)
    {
        return is_string($key) && strpos(trim($key), self::KEY_PREFIX) === 0;
    }

    /**
     * Build a Panel from a server row.
     *
     * Per call, from the row this service is assigned to, so several Dashmox
     * servers work with nothing held between calls. Where a new account lands
     * is Blesta's decision through its package groups, not this module's.
     */
    private function panel($row)
    {
        $meta = $row->meta;
        $host = trim($meta->host);
        $port = !empty($meta->port) ? (int) $meta->port : 8443;

        return new Panel(new Client('https://' . $host . ':' . $port, trim($meta->key)));
    }

    /**
     * What a package sells.
     *
     * Disk is the one allowance both systems agree on. Blesta also carries
     * bandwidth and mailbox counts; the panel meters transfer but does not cap
     * it, and mailboxes are a customer allowance rather than a per-website
     * number, so sending them would invent a limit the panel does not enforce
     * and the customer would later be told about.
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = new ModuleFields();

        $package = $fields->label(Language::_('Dashmox.package_fields.package', true), 'meta[package]');
        $package->attach($fields->fieldText('meta[package]', $this->Html->ifSet($vars->meta['package'])));
        $fields->setField($package);

        $runtime = $fields->label(Language::_('Dashmox.package_fields.runtime', true), 'meta[runtime]');
        $runtime->attach($fields->fieldSelect('meta[runtime]',
            ['php' => 'PHP', 'node' => 'Node.js', 'proxy' => 'Reverse proxy', 'static' => 'Static files'],
            $this->Html->ifSet($vars->meta['runtime'], 'php')));
        $fields->setField($runtime);

        return $fields;
    }

    /** The domain a service is for. */
    public function getServiceFields($vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);
        $fields = new ModuleFields();
        $domain = $fields->label(Language::_('Dashmox.service_fields.domain', true), 'dashmox_domain');
        $domain->attach($fields->fieldText('dashmox_domain', $this->Html->ifSet($vars->dashmox_domain)));
        $fields->setField($domain);

        return $fields;
    }

    public function validateService($package, array $vars = null)
    {
        $this->Input->setRules([
            'dashmox_domain' => [
                'format' => [
                    'rule' => ['matches', '/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
                    'message' => Language::_('Dashmox.!error.domain.format', true),
                ],
            ],
        ]);

        return $this->Input->validates($vars);
    }

    /**
     * Create the customer, then the website under it.
     *
     * The panel answers 202: the website is a record at once and is not
     * finished being built. Both ids are kept on the service, because every
     * later operation addresses them: a module that searches by domain will
     * one day find the wrong website, and a termination that found the wrong
     * one is not recoverable.
     */
    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $row = $this->getModuleRow();
        if (!$row) {
            $this->Input->setErrors(['module_row' => ['missing' => Language::_('Dashmox.!error.module_row.missing', true)]]);

            return;
        }
        if ($status !== 'active') {
            // Blesta calls this for pending services too. Nothing is created
            // until the service is meant to exist.
            return $this->fields($vars);
        }

        $client = $this->getClientDetails($vars);
        $domain = isset($vars['dashmox_domain']) ? trim($vars['dashmox_domain']) : '';
        try {
            $made = $this->panel($row)->createAccount([
                'name' => $client['name'],
                'email' => $client['email'],
                'company' => $client['company'],
                'address' => $client['address'],
                'city' => $client['city'],
                'postal_code' => $client['postcode'],
                'country' => $client['country'],
                'package_id' => isset($package->meta->package) ? $package->meta->package : '',
            ], [
                'name' => $domain,
                'domain' => $domain,
                'runtime' => isset($package->meta->runtime) ? $package->meta->runtime : 'php',
                'ssl' => true,
            ]);
        } catch (ApiError $e) {
            $this->Input->setErrors(['api' => ['response' => $this->explain($e)]]);

            return;
        } catch (Exception $e) {
            $this->Input->setErrors(['api' => ['response' => $e->getMessage()]]);

            return;
        }

        return [
            ['key' => 'dashmox_domain', 'value' => $domain, 'encrypted' => 0],
            ['key' => 'dashmox_customer_id', 'value' => $made['customer_id'], 'encrypted' => 0],
            ['key' => 'dashmox_site_id', 'value' => isset($made['site']['id']) ? $made['site']['id'] : '', 'encrypted' => 0],
        ];
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->setSuspended($service, true);
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->setSuspended($service, false);
    }

    private function setSuspended($service, $suspended)
    {
        $row = $this->getModuleRow();
        $ids = $this->recall($service);
        if ($ids['customer_id'] === '') {
            return null;
        }
        try {
            $this->panel($row)->setCustomerSuspended($ids['customer_id'], $suspended);
        } catch (ApiError $e) {
            $this->Input->setErrors(['api' => ['response' => $this->explain($e)]]);
        }

        return null;
    }

    /**
     * Every website first, then the customer.
     *
     * The panel refuses a customer who still owns websites and says how many,
     * so this has an order rather than being one call.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        $ids = $this->recall($service);
        if ($ids['customer_id'] === '') {
            return null;
        }
        try {
            $panel = $this->panel($row);
            if ($ids['site_id'] !== '') {
                $panel->deleteSite($ids['site_id'], $ids['domain']);
            }
            $panel->deleteCustomer($ids['customer_id'], '');
        } catch (ApiError $e) {
            $this->Input->setErrors(['api' => ['response' => $this->explain($e)]]);
        }

        return null;
    }

    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow();
        $ids = $this->recall($service);
        if ($ids['customer_id'] === '' || empty($package_to->meta->package)) {
            return null;
        }
        try {
            $this->panel($row)->setPackage($ids['customer_id'], $package_to->meta->package);
        } catch (ApiError $e) {
            $this->Input->setErrors(['api' => ['response' => $this->explain($e)]]);
        }

        return null;
    }

    /**
     * Whether the panel answers, and whether the key may do the work.
     *
     * More than "connected": a key that cannot read hosting packages is missing
     * a permission, and finding that here rather than at the first order is the
     * difference between a minute and a support ticket.
     */
    public function validateConnection($key, $host, $port)
    {
        try {
            $panel = new Panel(new Client('https://' . $host . ':' . (int) $port, $key));
            $panel->health();
            $panel->packages();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * What the customer sees: read-only, and nothing they can press.
     *
     * Every change stays in the panel, where it is built once rather than built
     * again here and kept in step for ever. What this earns is the trip not
     * taken: a customer who can see their disk is fine has no reason to open
     * anything.
     */
    public function getClientTabs($package)
    {
        return ['tabClientHosting' => Language::_('Dashmox.tab_client_hosting', true)];
    }

    public function tabClientHosting($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->view = new View('tab_client_hosting', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'dashmox' . DS);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $ids = $this->recall($service);
        $summary = ['domain' => $ids['domain'], 'problem' => '', 'disk' => null, 'transfer' => null, 'ssl_days' => null, 'status' => ''];
        if ($ids['site_id'] !== '') {
            try {
                $panel = $this->panel($this->getModuleRow());
                $site = $panel->site($ids['site_id']);
                $summary['status'] = isset($site['status']) ? $site['status'] : '';
                // Each figure on its own, because a panel that cannot answer one
                // of them should not take the whole page with it. Null means not
                // measured, which is a different claim from zero.
                $summary['disk'] = $this->measured(function () use ($panel, $ids) {
                    $storage = $panel->storage($ids['site_id']);

                    return isset($storage['bytes']) ? (int) $storage['bytes'] : null;
                });
                $summary['transfer'] = $this->measured(function () use ($panel, $ids) {
                    $moved = $panel->bandwidth($ids['site_id'], '30d');

                    return isset($moved['bytes']) ? (int) $moved['bytes'] : null;
                });
                $summary['ssl_days'] = $this->measured(function () use ($panel, $ids) {
                    $certificate = $panel->certificate($ids['site_id']);
                    if (!$certificate || empty($certificate['expires_at'])) {
                        return null;
                    }
                    $expires = strtotime($certificate['expires_at']);

                    return $expires ? (int) floor(($expires - time()) / 86400) : null;
                });
            } catch (ApiError $e) {
                $summary['problem'] = $this->explain($e);
            } catch (Exception $e) {
                $summary['problem'] = Language::_('Dashmox.tab_client_hosting.unreachable', true);
            }
        }
        $this->view->set('summary', $summary);

        return $this->view->fetch();
    }

    /** A figure, or null when it was not measured rather than zero. */
    private function measured(callable $read)
    {
        try {
            return $read();
        } catch (Exception $e) {
            return null;
        }
    }

    /** The two panel ids and the domain, from the service's own fields. */
    private function recall($service)
    {
        $out = ['domain' => '', 'customer_id' => '', 'site_id' => ''];
        if (empty($service->fields)) {
            return $out;
        }
        foreach ($service->fields as $field) {
            switch ($field->key) {
                case 'dashmox_domain': $out['domain'] = $field->value; break;
                case 'dashmox_customer_id': $out['customer_id'] = $field->value; break;
                case 'dashmox_site_id': $out['site_id'] = $field->value; break;
            }
        }

        return $out;
    }

    private function getClientDetails(array $vars = null)
    {
        $out = ['name' => '', 'email' => '', 'company' => '', 'address' => '', 'city' => '', 'postcode' => '', 'country' => ''];
        if (empty($vars['client_id'])) {
            return $out;
        }
        Loader::loadModels($this, ['Clients']);
        $client = $this->Clients->get($vars['client_id']);
        if (!$client) {
            return $out;
        }
        $out['name'] = trim($client->first_name . ' ' . $client->last_name);
        $out['email'] = $client->email;
        $out['company'] = isset($client->company) ? $client->company : '';
        $out['address'] = isset($client->address1) ? $client->address1 : '';
        $out['city'] = isset($client->city) ? $client->city : '';
        $out['postcode'] = isset($client->zip) ? $client->zip : '';
        $out['country'] = isset($client->country) ? $client->country : '';

        return $out;
    }

    /**
     * What a refusal should say to whoever reads it.
     *
     * The panel writes sentences meant for a person, so they are passed through
     * rather than replaced with a status code. The two worth adding to are the
     * ones where the panel's sentence is true and the reason is elsewhere.
     */
    private function explain(ApiError $e)
    {
        if ($e->isUnlicensed()) {
            return Language::_('Dashmox.!error.unlicensed', true) . ' (' . $e->getMessage() . ')';
        }
        if ($e->isTemporary()) {
            return Language::_('Dashmox.!error.temporary', true) . ' ' . $e->getMessage();
        }

        return $e->getMessage();
    }
}
