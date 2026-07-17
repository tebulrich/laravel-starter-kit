<?php

declare(strict_types=1);

namespace StarterKit\Setup;

use RuntimeException;

/**
 * Thrown when the operator chooses to abort setup (for example existing frontend → abort).
 */
final class SetupAbortedException extends RuntimeException {}
