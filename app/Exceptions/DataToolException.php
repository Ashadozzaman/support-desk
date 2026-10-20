<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A data-assistant lookup can't run (unknown name, invalid input, a report
 * the user may not see). Never shown as an error page: the message goes
 * back to the model as an error tool result, so it can correct itself or
 * tell the user.
 */
class DataToolException extends RuntimeException {}
