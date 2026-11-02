<?php

declare(strict_types=1);

namespace Asignua\FilamentXlsxImport\Support;

use RuntimeException;

/**
 * The dry run lost its transaction (an implicit commit): whatever it wrote may be in the database.
 */
final class DryRunAborted extends RuntimeException {}
