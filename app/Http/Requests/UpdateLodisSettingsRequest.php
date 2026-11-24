<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLodisSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:100'],
            'notification_channel' => ['required', 'string', Rule::in(['telegram', 'email', 'both'])],
            'notification_email' => ['nullable', 'email', 'max:255'],
            'ssl_reminder_thresholds' => ['required', 'string', 'regex:/^\d+(,\s*\d+)*$/'],
            'webserver_service_name' => ['required', 'string', 'max:100'],
            'win_acme_path' => ['required', 'string', 'max:255'],
            'win_acme_task_name' => ['nullable', 'string', 'max:150'],
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
            'ssl_reminder_thresholds.regex' => 'Format ambang batas hari harus berupa angka dipisahkan koma (contoh: 14,7,3,1).',
            'notification_email.email' => 'Format alamat email penerima notifikasi tidak valid.',
        ];
    }
}
