<?php

namespace App\Http\Requests;

use App\Enums\WorkspacePermission;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ! $user->is_demo && $user->can(WorkspacePermission::ManageUsers->value);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');
        $uniqueEmail = Rule::unique('users', 'email');

        if ($user instanceof User) {
            $uniqueEmail->ignore($user);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:254', $uniqueEmail],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12', 'max:72'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => __('workspace.name_invalid'),
            'email.unique' => __('workspace.email_taken'),
            'email.*' => __('workspace.email_invalid'),
            'password.*' => __('workspace.staff_password_invalid'),
        ];
    }
}
