<?php

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Assessment\Models\AssessmentScheme;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Assessment schemes. Presets (CBSE-style and a simple one) are only
 * starting points, shown with "check against your board's latest circular"
 * (owner's rule); everything in a scheme can be changed.
 *
 *   GET  /assessment-schemes            this year's schemes
 *   GET  /assessment-schemes/presets    the starter presets, each with its note
 *   POST /assessment-schemes { name, grades[], preset? }   a new scheme, filled from a preset if given
 *   PUT  /assessment-schemes/{id}       name, grades, terms, components, grade_scale, rules, status
 */
class SchemeController
{
    public function index(): JsonResponse
    {
        $year = AcademicYear::current();
        $rows = $year instanceof AcademicYear ? AssessmentScheme::query()->where('year_id', (string) $year->getKey())->orderBy('name')->get() : collect();

        return response()->json(['data' => $rows->map(fn (AssessmentScheme $s) => self::present($s))->values()]);
    }

    public function presets(): JsonResponse
    {
        $out = [];
        foreach ((array) config('assessment.presets') as $key => $preset) {
            $out[] = array_merge(['key' => $key], $preset);
        }

        return response()->json(['data' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageScheme', AssessmentScheme::class);
        $input = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'grades' => ['required', 'array', 'min:1', 'max:20'],
            'grades.*' => ['string', 'max:10'],
            'preset' => ['nullable', Rule::in(array_keys((array) config('assessment.presets')))],
        ]);
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'The school has no current academic year yet.', 422);
        $preset = isset($input['preset']) ? (array) config('assessment.presets.'.$input['preset']) : [];

        $scheme = AssessmentScheme::create([
            'year_id' => (string) $year->getKey(),
            'name' => $input['name'],
            'grades' => array_values($input['grades']),
            'terms' => $preset['terms'] ?? ['Term 1'],
            'components' => $preset['components'] ?? [],
            'grade_scale' => $preset['grade_scale'] ?? [],
            'rules' => $preset['rules'] ?? ['pass_percent' => 33, 'rounding' => 'half_up', 'best_of' => null, 'grace_marks' => 0],
            'preset' => $input['preset'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json(['data' => self::present($scheme)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manageScheme', AssessmentScheme::class);
        $scheme = AssessmentScheme::query()->findOrFail($id);
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'grades' => ['sometimes', 'array', 'min:1', 'max:20'],
            'grades.*' => ['string', 'max:10'],
            'terms' => ['sometimes', 'array', 'min:1', 'max:4'],
            'terms.*' => ['string', 'min:1', 'max:40'],
            'components' => ['sometimes', 'array', 'max:12'],
            'components.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,20}$/'],
            'components.*.name' => ['required', 'string', 'max:60'],
            'components.*.max' => ['required', 'numeric', 'min:1', 'max:1000'],
            'components.*.weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'grade_scale' => ['sometimes', 'array', 'max:15'],
            'grade_scale.*.grade' => ['required', 'string', 'max:6'],
            'grade_scale.*.min' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules' => ['sometimes', 'array'],
            'rules.pass_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'rules.rounding' => ['sometimes', Rule::in(['half_up', 'half_even', 'up', 'down', 'none'])],
            'rules.best_of' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'rules.grace_marks' => ['sometimes', 'numeric', 'min:0', 'max:20'],
            'status' => ['sometimes', Rule::in(['draft', 'active'])],
        ]);

        if (isset($input['components'])) {
            $keys = array_column($input['components'], 'key');
            if (count($keys) !== count(array_unique($keys))) {
                throw new ApiException('component_duplicate', 'Each component needs its own key.', 422);
            }
            $weight = array_sum(array_column($input['components'], 'weight'));
            if ($input['components'] !== [] && abs($weight - 100) > 0.01) {
                throw new ApiException('weights_total', "The weightages add up to {$weight}%; they must add up to 100%.", 422, ['total' => $weight]);
            }
        }
        if (isset($input['rules'])) {
            $input['rules'] = array_merge($scheme->rules, $input['rules']);
        }

        $scheme->fill($input)->save();

        return response()->json(['data' => self::present($scheme)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(AssessmentScheme $s): array
    {
        $preset = $s->preset !== null ? (array) config('assessment.presets.'.$s->preset) : null;

        return [
            'id' => (string) $s->getKey(), 'name' => $s->name, 'grades' => $s->grades, 'terms' => $s->terms, 'components' => $s->components,
            'grade_scale' => $s->grade_scale, 'rules' => $s->rules, 'status' => $s->status, 'preset' => $s->preset,
            'preset_label' => $preset['label'] ?? null, 'preset_note' => $preset['note'] ?? null,
        ];
    }
}
