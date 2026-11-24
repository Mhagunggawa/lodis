<?php

namespace App\Http\Requests;

use App\Models\MonitoredDomain;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMonitoredDomainRequest extends FormRequest
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
        /** @var MonitoredDomain|int|string|null $domain */
        $domain = $this->route('domain');
        $domainId = $domain instanceof MonitoredDomain ? $domain->id : $domain;

        return [
            'domain_name' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/i',
                Rule::unique('monitored_domains', 'domain_name')->ignore($domainId),
            ],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
