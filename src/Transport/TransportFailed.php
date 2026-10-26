<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel\Transport;

use RuntimeException;

/** The service could not be reached, or said no to everything (a wrong secret): nothing was taken. */
final class TransportFailed extends RuntimeException {}
