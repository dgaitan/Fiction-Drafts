<?php

declare( strict_types=1 );

namespace FictionDrafts\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Thrown when a bound service exists but cannot be constructed.
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface {
}
