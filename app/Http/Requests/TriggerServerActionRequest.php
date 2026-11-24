<?php

namespace App\Http\Requests;

use App\Models\ServerActionLog;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriggerServerActionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Strict command whitelisting validation: only reload_webserver and renew_ssl are allowed.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in(ServerActionLog::allowedActions()),
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Kunci aksi wajib disertakan.',
            'action.in' => 'Perintah ditolak oleh Command Whitelisting! Hanya reload_webserver dan renew_ssl yang diizinkan.',
        ];
    }
}
