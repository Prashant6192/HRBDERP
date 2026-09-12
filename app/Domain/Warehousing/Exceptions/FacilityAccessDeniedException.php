<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The user holds the permission but is not assigned to the facility or
 * store the action touches. Rendered as 403 like any other denial.
 */
class FacilityAccessDeniedException extends AuthorizationException {}
