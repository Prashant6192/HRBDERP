<?php

declare(strict_types=1);

/*
 * The password reset messages, in the ERP's words.
 *
 * "sent" is shown for every well-formed request, whether or not the
 * address has an account, so the form cannot be used to find out who
 * works here. "user" is kept for completeness, but the request screen no
 * longer shows it; see App\Http\Controllers\Auth\PasswordResetLinkController.
 */
return [
    'reset' => 'Your password has been changed. Sign in with the new one.',
    'sent' => 'If that address belongs to an account here, a reset link is on its way. It can take a minute or two; check spam if it does not arrive.',
    'throttled' => 'Please wait a minute before asking again.',
    'token' => 'This reset link is invalid or has expired. Ask for a new one.',
    'user' => 'If that address belongs to an account here, a reset link is on its way.',
];
