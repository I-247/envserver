<?php

namespace App\Http\Requests\Variables;

use App\Enums\ConflictStrategy;
use App\Support\EnvFileParser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A pasted .env file, on its way into an environment.
 */
class ImportEnvFileRequest extends FormRequest
{
    /**
     * The parsed contents, filled in while validating.
     *
     * @var array<string, string>|null
     */
    private ?array $parsed = null;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'contents' => ['required', 'string', 'max:262144'],
            'strategy' => ['sometimes', Rule::enum(ConflictStrategy::class)],
        ];
    }

    /**
     * Parse the paste, and report a parse error as a validation error.
     *
     * Parsing during validation keeps the controller from having to deal with
     * malformed input at all, and means the person pasting hears about a
     * broken line before anything has been written.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $this->parsed = app(EnvFileParser::class)->parse($this->string('contents')->value());
                } catch (InvalidArgumentException $exception) {
                    $validator->errors()->add('contents', $exception->getMessage());

                    return;
                }

                if ($this->parsed === []) {
                    $validator->errors()->add('contents', 'There is no variable in here to import.');
                }
            },
        ];
    }

    /**
     * The variables the paste holds.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        return $this->parsed ?? [];
    }

    /**
     * What to do with a key the environment already has.
     */
    public function strategy(): ConflictStrategy
    {
        return ConflictStrategy::tryFrom((string) $this->input('strategy')) ?? ConflictStrategy::Overwrite;
    }
}
