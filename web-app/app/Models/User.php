<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public static function localQuickSignInEnabled(?Request $request = null): bool
    {
        $loopbackHosts = ['localhost', '127.0.0.1', '[::1]'];

        return app()->environment('local')
            && config('app.debug') === true
            && in_array(parse_url(config('app.url'), PHP_URL_HOST), $loopbackHosts, true)
            && ($request === null || (
                in_array($request->getHost(), $loopbackHosts, true)
                && in_array($request->ip(), ['127.0.0.1', '::1'], true)
            ));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_demo' => 'boolean',
        ];
    }
}
