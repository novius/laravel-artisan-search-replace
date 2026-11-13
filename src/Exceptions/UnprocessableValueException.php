<?php

namespace Novius\LaravelArtisanSearchReplace\Exceptions;

use RuntimeException;

/**
 * Thrown when a value cannot be safely processed (e.g. corrupted PHP serialized data).
 * The value is then left untouched.
 */
class UnprocessableValueException extends RuntimeException {}
