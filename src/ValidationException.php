<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Thrown when user input is rejected. The message is safe to show back
 * to the person who submitted the form or called the API.
 */
final class ValidationException extends RuntimeException
{
}
