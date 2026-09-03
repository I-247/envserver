<?php

namespace App\Http\Requests\Variables;

use App\Actions\Variables\ResolveEnvironmentVariables;
use App\Data\ResolvedVariable;
use App\Models\Environment;
use App\Models\Team;
use App\Models\Variable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShareVariableRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->route('current_team');

        return [
            /**
             * Scoped to the team, which is also the encryption boundary: a
             * variable from another team is sealed with a key this team
             * cannot open, so it must never become selectable.
             */
            'variable_id' => [
                'required',
                Rule::exists('variables', 'id')->where(
                    'team_id',
                    $team instanceof Team ? $team->id : 0,
                ),
            ],
            'alias_key' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z_][A-Za-z0-9_]*\z/'],
        ];
    }

    /**
     * Reject shares this environment cannot actually hold.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $environment = $this->route('environment');
                $variable = Variable::find((int) $this->input('variable_id'));

                if (! $environment instanceof Environment || $variable === null) {
                    return;
                }

                if (! $variable->isOfferedToOtherProjects()) {
                    $validator->errors()->add(
                        'variable_id',
                        'That variable is not shared by its project.',
                    );

                    return;
                }

                if ($environment->assignments()->where('variable_id', $variable->id)->exists()) {
                    $validator->errors()->add('variable_id', 'This environment already uses that variable.');

                    return;
                }

                $key = $this->input('alias_key') ?: $variable->key;

                /**
                 * The same collision rule as creating a variable: an
                 * environment renders one .env, so one key can only resolve
                 * to one value. An alias is the way out, hence the hint.
                 */
                $taken = app(ResolveEnvironmentVariables::class)
                    ->handle($environment)
                    ->contains(fn (ResolvedVariable $entry) => $entry->key === $key);

                if ($taken) {
                    $validator->errors()->add(
                        'alias_key',
                        "This environment already exposes {$key}. Give the shared variable a different name here.",
                    );
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'alias_key.regex' => 'Use letters, digits and underscores only, and do not start with a digit.',
        ];
    }
}
