<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table = 'users';

    protected $dateFormat = 'Y-m-d H:i:s.v';

    // The table only has created_at (no updated_at / remember_token).
    const UPDATED_AT = null;

    protected $rememberTokenName = false;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password'];
}
