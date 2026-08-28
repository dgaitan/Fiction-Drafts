<?php

/**
 * PHPUnit bootstrap for unit tests.
 *
 * Unit tests run without a WordPress installation — they exercise the domain
 * layer and the container in isolation.
 *
 * Integration tests (tests/Integration/) require the WordPress test suite and
 * arrive with Sprint 2, when there is persistence to integrate against.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/stubs/wordpress-stubs.php';
require_once __DIR__ . '/stubs/rest-stubs.php';
require_once __DIR__ . '/stubs/download-stubs.php';
