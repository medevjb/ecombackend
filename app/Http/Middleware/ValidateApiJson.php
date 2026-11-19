<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiJson
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        abort_if($request->isJson() && $content !== '' && ! json_validate($content), 400);

        return $next($request);
    }
}
