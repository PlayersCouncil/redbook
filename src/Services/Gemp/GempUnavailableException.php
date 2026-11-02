<?php

namespace App\Services\Gemp;

use RuntimeException;

/** Gemp couldn't be reached or answered with a server error. Callers carry on without it. */
class GempUnavailableException extends RuntimeException
{
}
