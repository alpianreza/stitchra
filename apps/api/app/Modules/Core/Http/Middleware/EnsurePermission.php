<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request$request,Closure$next,string$permission):Response
    {
        $user=$request->user();if(!$user)abort(401,'Unauthenticated.');
        if($user->currentAccessToken()&&!$user->tokenCan('api:access'))abort(403,'Token ini tidak boleh mengakses endpoint administrasi.');
        if(!$user->hasPermission($permission))abort(403,"Permission [{$permission}] diperlukan.");
        return$next($request);
    }
}
