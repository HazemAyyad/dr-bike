<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('lang', 'en'); // default to English
        if (!in_array($lang, ['en', 'ar'])) {
            $lang = 'en'; // fallback
        }
        App::setLocale($lang);
        return $next($request);
    }
}
