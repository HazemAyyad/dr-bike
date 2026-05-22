<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $lang = $request->header('lang', config('app.locale', 'ar'));
        if (! in_array($lang, ['en', 'ar'], true)) {
            $lang = config('app.locale', 'ar');
        }
        App::setLocale($lang);
        return $next($request);
    }
}
