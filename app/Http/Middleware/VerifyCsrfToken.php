<?php

namespace App\Http\Middleware;

// Laravel 13 renamed this middleware; retain the same CSRF protection on local Laravel 10 installs.
if (! class_exists(__NAMESPACE__.'\\InventoryCompatibleCsrf', false)) {
    class_alias(class_exists(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
        ? \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class
        : \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, __NAMESPACE__.'\\InventoryCompatibleCsrf');
}

class VerifyCsrfToken extends InventoryCompatibleCsrf
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'iclock/*',
        'public/iclock/*',
    ];
}
