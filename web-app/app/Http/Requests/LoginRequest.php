<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => __('workspace.email_invalid'),
            'email.email' => __('workspace.email_invalid'),
            'email.max' => __('workspace.email_invalid'),
            'password.required' => __('workspace.password_invalid'),
            'password.string' => __('workspace.password_invalid'),
            'password.max' => __('workspace.password_invalid'),
        ];
    }
}
