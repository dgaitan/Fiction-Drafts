<?php
/**
 * Sprint 0 acceptance harness.
 *
 * Simulates the WordPress plugin lifecycle with a minimal hook system, loads
 * ONLY Fiction Drafts' bundled Action Scheduler (no WooCommerce, no other
 * plugin), fires plugins_loaded, and asserts the as_*() API is available.
 */

declare(strict_types=1);

$GLOBALS['__hooks'] = [];
$GLOBALS['__did']   = [];
$GLOBALS['__doing'] = null;

function add_action(string $hook, $cb, int $priority = 10, int $args = 1): bool {
    $GLOBALS['__hooks'][$hook][$priority][] = $cb;
    return true;
}
function add_filter(string $hook, $cb, int $priority = 10, int $args = 1): bool {
    return add_action($hook, $cb, $priority, $args);
}
function remove_action(string $hook, $cb, int $priority = 10): bool { return true; }
function remove_filter(string $hook, $cb, int $priority = 10): bool { return true; }
function do_action(string $hook, ...$args): void {
    $GLOBALS['__did'][$hook] = ($GLOBALS['__did'][$hook] ?? 0) + 1;
    $prev = $GLOBALS['__doing'];
    $GLOBALS['__doing'] = $hook;
    $callbacks = $GLOBALS['__hooks'][$hook] ?? [];
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $cb) { $cb(...$args); }
    }
    $GLOBALS['__doing'] = $prev;
}
function apply_filters(string $hook, $value, ...$args) { return $value; }
function did_action(string $hook): int { return $GLOBALS['__did'][$hook] ?? 0; }
function doing_action(?string $hook = null): bool {
    return null === $hook ? null !== $GLOBALS['__doing'] : $GLOBALS['__doing'] === $hook;
}
function plugin_dir_path(string $file): string { return rtrim(dirname($file), '/') . '/'; }
function plugin_dir_url(string $file): string { return 'https://example.test/'; }
function plugin_basename(string $file): string { return basename(dirname($file)) . '/' . basename($file); }
function register_activation_hook(string $file, $cb): void {}
function register_deactivation_hook(string $file, $cb): void {}
function __(string $t, string $d = 'default'): string { return $t; }
function esc_html__(string $t, string $d = 'default'): string { return $t; }
function wp_json_encode($data, int $o = 0, int $d = 512) { return json_encode($data, $o, $d); }

require_once __DIR__ . '/wp-shim.php';
define('ABSPATH', __DIR__ . '/');

$plugin = dirname(__DIR__, 2) . '/fiction-drafts.php';

echo "== before loading Fiction Drafts ==\n";
printf("  as_enqueue_async_action exists: %s\n", function_exists('as_enqueue_async_action') ? 'yes' : 'no');
printf("  ActionScheduler_Versions class: %s\n", class_exists('ActionScheduler_Versions', false) ? 'yes' : 'no');

require_once $plugin;

echo "== after require (file scope, before plugins_loaded) ==\n";
printf("  ActionScheduler_Versions class: %s\n", class_exists('ActionScheduler_Versions', false) ? 'yes' : 'no');
printf("  FictionDrafts\\Plugin class:     %s\n", class_exists(\FictionDrafts\Plugin::class) ? 'yes' : 'no');

do_action('plugins_loaded');

echo "== after plugins_loaded ==\n";
$versions = \ActionScheduler_Versions::instance();
printf("  registered AS versions:  %s\n", implode(', ', array_keys($versions->get_versions())));
printf("  latest_version():        %s\n", $versions->latest_version());
printf("  as_enqueue_async_action: %s\n", function_exists('as_enqueue_async_action') ? 'yes' : 'no');
printf("  as_schedule_recurring:   %s\n", function_exists('as_schedule_recurring_action') ? 'yes' : 'no');
printf("  as_unschedule_all:       %s\n", function_exists('as_unschedule_all_actions') ? 'yes' : 'no');
printf("  Plugin booted:           %s\n", \FictionDrafts\Plugin::instance()->isBooted() ? 'yes' : 'no');

$ok = function_exists('as_enqueue_async_action')
    && function_exists('as_schedule_recurring_action')
    && function_exists('as_unschedule_all_actions')
    && '4.1.0' === $versions->latest_version();

echo $ok ? "\nPASS: Action Scheduler is available with no other plugin loaded.\n"
         : "\nFAIL\n";
exit($ok ? 0 : 1);
