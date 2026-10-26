<?php

declare(strict_types=1);

namespace MyBeast\Errors\Laravel;

use RuntimeException;

/** The service did not confirm an erasure (down, refused the secret): the host should try again - a queued job does. */
final class ErasureFailed extends RuntimeException {}
