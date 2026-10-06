<?php
declare(strict_types=1);

use Cake\Core\Configure;

/**
 * Plugin config bootstrap (auto-required by BasePlugin::bootstrap()).
 *
 * Hosts define their own 'EdgeCache' block in config/app.php; write the defaults under any keys the host has not set
 * and never overwrite host values (shallow per-key `+` merge, the TheMusicDev plugin-config convention).
 */
$existing = (array)Configure::read('EdgeCache', []);
$defaults = (require __DIR__ . '/app_default.php')['EdgeCache'];
$merged = $existing + $defaults;
// The option groups merge one level deeper: a host that sets only `purge.driver` keeps the default `zoneId`.
foreach (['public', 'csrf', 'purge'] as $group) {
    $merged[$group] = (array)($existing[$group] ?? []) + $defaults[$group];
}
Configure::write('EdgeCache', $merged);
