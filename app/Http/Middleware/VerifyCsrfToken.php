<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class CustomCsrf extends Middleware
{
    /**
     * Le URI da esentare dal controllo CSRF.
     *
     * @var array<int,string>
     */
    protected $except = [
        'reels/*/status',
    ];
}
