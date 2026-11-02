<?php

namespace App\Services\Gemp;

use RuntimeException;

/** Gemp answered but refused the request (400): bad deck contents, unknown cards. */
class GempRejectedException extends RuntimeException
{
}
