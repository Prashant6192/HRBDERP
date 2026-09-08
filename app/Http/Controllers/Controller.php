<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 11 and later leave the base controller empty. The ERP puts
 * AuthorizesRequests back on it, because every controller in this application
 * authorises: there is no screen that anyone may see by virtue of being signed
 * in alone.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
