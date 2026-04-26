<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TimerUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role?->name === 'Timer';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_display' => ['sometimes', 'boolean'],
            'is_countdown' => ['sometimes', 'boolean'],
            'second' => ['sometimes', 'integer', 'min:1', 'max:35999'],
            'is_autostop' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['running', 'paused', 'stopped'])],
        ];
    }
}
