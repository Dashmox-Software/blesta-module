# Blesta Provisioning Module for Dashmox

Sell hosting from Blesta and let it create, suspend, restore and terminate
accounts on your own Linux server. This is the official Blesta module for
[Dashmox](https://dashmox.com), a hosting control panel you run yourself.

Free to use, and free to read before you install it. Nothing here is obfuscated
or encoded.

## What it does

| In Blesta | On your server |
| --- | --- |
| Add service | Creates the customer, then their website under it |
| Suspend and Unsuspend | Flips the whole customer, which signs out their people and reconfigures their websites |
| Cancel service | Removes every website first, then the customer |
| Change package | Moves the customer onto another hosting plan you already sell |
| Manage module | Checks the panel is reachable and the credential works |

Your client also gets a read only tab on their service: status, disk used,
transfer over the last thirty days and when the certificate expires.

## Requirements

* A Dashmox panel on your own server. See [dashmox.com](https://dashmox.com).
* **Blesta 5 or newer**, with PHP 8.1 or newer.
* **An integration key**, which comes with a Pro or Business licence. Create one
  in the panel under **Server**, then **Integrations**.

Grant the key these permissions: `customers.manage`, `sites.create`,
`sites.view`, `sites.edit`, `sites.delete`, `server.view`. It reaches only the
routes that name a permission it holds, so nothing else is open to it whatever
else you tick.

The key belongs to the installation rather than to a person, so it does not stop
working when the administrator who made it leaves.

## Installing

1. Copy the `components` folder into your Blesta root, so the module lands at
   `components/modules/dashmox/`.
2. In Blesta go to **Settings**, then **Company**, then **Modules**, and install
   **Dashmox**.
3. Add a server to the module. Put your panel's address in **Hostname** and the
   integration key in **API key**.
4. Blesta validates both when you save, so a wrong address or a key the panel
   does not accept is refused there rather than at the first order.

Then add a package that uses this module and set its name to a hosting plan that
exists on the panel.

## What it does not do

Stated plainly, because a module that quietly does nothing is worse than one
that says it cannot.

* **No single sign on.** The panel mints no one time session, so there is no
  "log in to your panel" button. Every hosted domain answers `/dashmox` with a
  redirect to the sign in page, so your client can still get there.
* **No password changes.** The route exists on the panel and is deliberately
  closed to integration credentials. Setting a website account's password is the
  difference between an integration doing an account's work and becoming the
  account.
* **No bandwidth as a package limit.** Transfer is measured and shown on the
  service tab, and it is not metered as a per website allowance.

## How it behaves when things go wrong

Provisioning is asynchronous. Creating a website answers with the job that builds
it, so the module records the website and polls its status rather than assuming
the work finished.

Two refusals are not failures and are retried rather than reported: a website
with work still queued, and one already being removed. Both clear on their own.

If the panel's licence lapses, every request for a key is refused and the module
says so as something for you to fix rather than as a credential problem. Nothing
is deleted, and it works again once a licence is applied.

## Tests

Two suites, neither of which needs Blesta or a network.

```
php tests/client_test.php
php tests/blesta_test.php
```

`blesta_test.php` covers the module's own logic: the fields it declares, how it
validates a hostname and a key, and how it maps a package onto a hosting plan.

## Support

Found a problem? Open an issue on this repository.

Pro and Business customers get a guaranteed reply through support at
[dashmox.com](https://dashmox.com). Issues here are answered when we can. Pull
requests are read, and not every one will be merged: this module provisions real
hosting, and a mistake in it suspends somebody's clients.

## Licence

MIT. Use it, change it, ship it inside whatever you are running. See
[LICENSE](LICENSE).

## The other two

The same thing exists for other billing systems, built on the same client:

* [WHMCS provisioning module](https://github.com/Dashmox-Software/whmcs-provisioning-module)
* [FOSSBilling server manager](https://github.com/Dashmox-Software/fossbilling-server-manager)
