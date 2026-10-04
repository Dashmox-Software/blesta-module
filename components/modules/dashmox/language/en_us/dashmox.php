<?php

/**
 * What this module says, in one place.
 *
 * Blesta keeps a module's words out of its code, which is a better habit than
 * the other two integrations have: a refusal here is a line somebody can read
 * and change without opening the thing that refuses.
 */

$lang['Dashmox.name'] = 'Dashmox';
$lang['Dashmox.description'] = 'Provision hosting on a Dashmox server.';

$lang['Dashmox.row_meta.host'] = 'Panel hostname';
$lang['Dashmox.row_meta.port'] = 'Port';
$lang['Dashmox.row_meta.key'] = 'Integration key';

$lang['Dashmox.package_fields.package'] = 'Panel package id';
$lang['Dashmox.package_fields.runtime'] = 'What new websites run';

$lang['Dashmox.service_fields.domain'] = 'Domain';

$lang['Dashmox.tab_client_hosting'] = 'Hosting';
$lang['Dashmox.tab_client_hosting.unreachable'] = 'The panel could not be reached just now.';
$lang['Dashmox.tab_client_hosting.not_measured'] = 'Not measured yet';

// The refusals. Each says what to do next, because a message that only says
// what went wrong leaves somebody to guess the rest.
$lang['Dashmox.!error.host.valid'] = 'Enter the panel hostname, such as cp.example.com.';
$lang['Dashmox.!error.key.valid'] = 'That does not look like a Dashmox integration key: they begin with "dashmox_". Make one in the panel under Server, then Integrations.';
$lang['Dashmox.!error.domain.format'] = 'Enter the domain this website will answer to.';
$lang['Dashmox.!error.module_row.missing'] = 'This package is not assigned to a Dashmox server.';
$lang['Dashmox.!error.unlicensed'] = 'The panel has no Pro or Business licence in force, so it refuses every integration request. The key is kept and works again once a licence is applied.';
$lang['Dashmox.!error.temporary'] = 'The panel is still working on this website. This clears on its own; try again shortly.';
