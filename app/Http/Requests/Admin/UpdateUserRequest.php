<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class UpdateUserRequest extends StoreUserRequest
{
    protected function uniqueEmail(): Unique
    {
        /** @var User $user */
        $user = $this->route('user');

        return Rule::unique('users', 'email')->ignore($user->id);
    }
}
