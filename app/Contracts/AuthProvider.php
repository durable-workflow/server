<?php

namespace App\Contracts;

use App\Auth\Principal;
use Illuminate\Http\Request;

interface AuthProvider
{
    public function authenticate(Request $request): Principal;

    /**
     * @param  array<string, mixed>  $resource
     */
    public function authorize(Principal $principal, string $action, array $resource = []): bool;
}
