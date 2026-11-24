<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMonitoredDomainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('domain_name')) {
            $clean = strtolower(trim((string) $this->input('domain_name')));
            $clean = (string) preg_replace('#^https?://#i', '', $clean);
            $clean = explode('/', $clean)[0] ?? $clean;
            $clean = explode(':', $clean)[0] ?? $clean;

            $this->merge([
                'domain_name' => $clean,
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'domain_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i',
                Rule::unique('monitored_domains', 'domain_name'),
            ],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Custom validation messages in Indonesian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'domain_name.required' => 'Nama domain wajib diisi.',
            'domain_name.regex' => 'Format nama domain tidak valid (contoh: portal.instansi.go.id).',
            'domain_name.unique' => 'Domain ini sudah terdaftar dalam daftar pantauan.',
            'port.required' => 'Port wajib diisi.',
            'port.min' => 'Port minimal adalah 1.',
            'port.max' => 'Port maksimal adalah 65535.',
        ];
    }
}
