<?php

namespace App\Support\Privacy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `no-aadhaar`, part of the `school` group: any request whose body
 * holds a full Aadhaar number in any field is refused with 422
 * aadhaar_not_allowed before it reaches a controller. Uploaded files (Excel
 * imports) are checked row by row by the importer.
 */
class RefuseFullAadhaar
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            Aadhaar::refuseIn($request->except(array_keys($request->allFiles())));
        }

        return $next($request);
    }
}
