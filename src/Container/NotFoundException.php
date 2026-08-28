<?php

declare( strict_types=1 );

namespace FictionDrafts\Container;

use Psr\Container\NotFoundExceptionInterface;
use InvalidArgumentException;

/**
 * Thrown when no service is bound under the requested identifier.
 */
final class NotFoundException extends InvalidArgumentException implements NotFoundExceptionInterface {
}
