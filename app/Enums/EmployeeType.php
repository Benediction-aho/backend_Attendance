<?php

namespace App\Enums;

use App\Support\SignupText;
use Illuminate\Validation\Rule;

enum EmployeeType: string
{
    case Intern = 'intern';
    case Employee = 'employee';
    /** Neither an intern nor an employee: the person types what they are (users.employee_type_other). */
    case Other = 'other';

    public const OTHER_MAX = 60;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }

    /**
     * Validation of the type and of its free text, shared by the public sign-up and the admin creation.
     * The free text is required with "other" and follows the same no-link rules as a job title (REL-03).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'employee_type' => ['required', Rule::in(self::values())],
            'employee_type_other' => [
                'nullable', 'required_if:employee_type,other', 'string', 'max:'.self::OTHER_MAX,
                'regex:'.SignupText::JOB_TITLE_PATTERN, 'not_regex:'.SignupText::LINK_PATTERN,
            ],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'employee_type.required' => 'Choose Intern, Employee or Other.',
            'employee_type.in' => 'Choose Intern, Employee or Other.',
            'employee_type_other.required_if' => 'Tell us what you are joining as.',
            'employee_type_other.regex' => 'Use letters, digits, spaces and simple punctuation only.',
            'employee_type_other.not_regex' => 'Links are not allowed here.',
        ];
    }

    /** The free text kept for a type: only "other" carries one. */
    public static function otherText(string $type, mixed $text): ?string
    {
        if ($type !== self::Other->value || ! is_string($text)) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
