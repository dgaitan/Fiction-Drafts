<?php
declare(strict_types=1);

function trailingslashit(string $s): string { return rtrim($s, '/\\') . '/'; }
function untrailingslashit(string $s): string { return rtrim($s, '/\\'); }
function is_admin(): bool { return false; }
function is_multisite(): bool { return false; }
function wp_doing_ajax(): bool { return false; }
function wp_doing_cron(): bool { return false; }
function get_option(string $k, $default = false) { return $default; }
function update_option(string $k, $v, $a = null): bool { return true; }
function add_option(string $k, $v = '', $d = '', $a = 'yes'): bool { return true; }
function delete_option(string $k): bool { return true; }
function get_transient(string $k) { return false; }
function set_transient(string $k, $v, int $e = 0): bool { return true; }
function delete_transient(string $k): bool { return true; }
function wp_next_scheduled(string $h, array $a = []) { return false; }
function wp_schedule_event(int $t, string $r, string $h, array $a = []) { return true; }
function wp_clear_scheduled_hook(string $h, array $a = []): void {}
function has_action(string $h, $cb = false) { return false; }
function has_filter(string $h, $cb = false) { return false; }
function did_filter(string $h): int { return 0; }
function esc_attr(string $t): string { return $t; }
function esc_html(string $t): string { return $t; }
function esc_url_raw(string $u): string { return $u; }
function sanitize_key(string $k): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $k)); }
function wp_parse_args($a, $d = []): array { return array_merge((array) $d, (array) $a); }
function absint($v): int { return abs((int) $v); }
function current_time(string $type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); }
function get_current_blog_id(): int { return 1; }
function wp_using_ext_object_cache(): bool { return false; }
function wp_cache_get($k, $g = '') { return false; }
function wp_cache_set($k, $v, $g = '', $e = 0): bool { return true; }
function wp_cache_delete($k, $g = ''): bool { return true; }
function _doing_it_wrong(string $f, string $m, string $v): void {}
function apply_filters_deprecated(string $h, array $a, string $v, string $r = '') { return $a[0] ?? null; }
function do_action_deprecated(string $h, array $a, string $v, string $r = ''): void {}
function wp_normalize_path(string $p): string { return str_replace('\\', '/', $p); }
