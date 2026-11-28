<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\Fees\Domain\DemandRaiser;
use App\Modules\Fees\Domain\Money;
use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Fees\Models\FeeHead;
use App\Modules\Fees\Models\FeePlan;
use App\Modules\Fees\Models\StudentFee;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Fee set-up (fees.setup: Chief, Principal, Clerk). Amounts arrive as
 * rupee strings ("1250.50") and are stored as whole paise.
 *
 *   GET/POST/PATCH /fees/heads, /fees/categories
 *   GET/POST/PUT   /fees/plans
 *   GET/PUT        /fees/students/{id}/setup   category, plan, joining date, overrides
 *   POST           /fees/demands/raise          raise what is due now (also nightly)
 */
class FeeSetupController
{
    public function heads(): JsonResponse
    {
        Gate::authorize('fees.setup');

        return response()->json(['data' => FeeHead::query()->orderBy('name')->get()->map(fn (FeeHead $h) => ['id' => (string) $h->getKey(), 'name' => $h->name, 'code' => $h->code, 'status' => $h->status])->values()]);
    }

    public function storeHead(Request $request): JsonResponse
    {
        Gate::authorize('fees.setup');
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60'], 'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/']]);
        $code = Str::upper($input['code'] ?? Str::slug($input['name'], '_'));
        if (FeeHead::query()->where('code', $code)->exists()) {
            throw new ApiException('code_taken', 'A fee head with that code already exists.', 422);
        }
        $head = FeeHead::create(['name' => trim($input['name']), 'code' => $code]);

        return response()->json(['data' => ['id' => (string) $head->getKey(), 'name' => $head->name, 'code' => $head->code, 'status' => $head->status]], 201);
    }

    public function updateHead(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.setup');
        $head = FeeHead::query()->findOrFail($id);
        $input = $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:60'], 'status' => ['sometimes', Rule::in(['active', 'archived'])]]);
        $head->fill($input)->save();

        return response()->json(['data' => ['id' => (string) $head->getKey(), 'name' => $head->name, 'code' => $head->code, 'status' => $head->status]]);
    }

    public function categories(): JsonResponse
    {
        Gate::authorize('fees.setup');

        return response()->json(['data' => FeeCategory::query()->orderBy('name')->get()->map(fn (FeeCategory $c) => ['id' => (string) $c->getKey(), 'name' => $c->name, 'code' => $c->code, 'status' => $c->status])->values()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        Gate::authorize('fees.setup');
        $input = $request->validate(['name' => ['required', 'string', 'min:2', 'max:60']]);
        $code = Str::upper(Str::slug($input['name'], '_'));
        if (FeeCategory::query()->where('code', $code)->exists()) {
            throw new ApiException('code_taken', 'A category with that name already exists.', 422);
        }
        $c = FeeCategory::create(['name' => trim($input['name']), 'code' => $code]);

        return response()->json(['data' => ['id' => (string) $c->getKey(), 'name' => $c->name, 'code' => $c->code, 'status' => $c->status]], 201);
    }

    public function plans(): JsonResponse
    {
        Gate::authorize('fees.setup');
        $year = AcademicYear::current();

        return response()->json(['data' => $year === null ? [] : FeePlan::query()->where('year_id', (string) $year->getKey())->orderBy('name')->get()->map(fn (FeePlan $p) => self::plan($p))->values()]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        Gate::authorize('fees.setup');
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $plan = FeePlan::create(['year_id' => (string) $year->getKey()] + $this->planInput($request, true));

        return response()->json(['data' => self::plan($plan)], 201);
    }

    /** Changes only instalments not raised yet; raised dues stay as they are. */
    public function updatePlan(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.setup');
        $plan = FeePlan::query()->findOrFail($id);
        $plan->fill($this->planInput($request, false))->save();

        return response()->json(['data' => self::plan($plan)]);
    }

    public function studentSetup(string $id): JsonResponse
    {
        Gate::authorize('fees.setup');
        $student = Student::query()->findOrFail($id);
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $profile = StudentFee::query()->where('student_id', $id)->where('year_id', (string) $year->getKey())->first();

        return response()->json(['data' => self::profile($student, $profile)]);
    }

    public function updateStudentSetup(Request $request, string $id): JsonResponse
    {
        Gate::authorize('fees.setup');
        $student = Student::query()->findOrFail($id);
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $input = $request->validate([
            'plan_id' => ['nullable', 'string', 'max:30'], 'category_id' => ['nullable', 'string', 'max:30'], 'joined_on' => ['nullable', 'date_format:Y-m-d'],
            'overrides' => ['sometimes', 'array', 'max:20'], 'overrides.*.head_id' => ['required', 'string', 'max:30'],
            'overrides.*.from' => ['nullable', 'date_format:Y-m-d'], 'overrides.*.to' => ['nullable', 'date_format:Y-m-d'], 'overrides.*.amount' => ['required', 'string', 'max:15'],
        ]);
        if (($input['plan_id'] ?? null) !== null && ! FeePlan::query()->where('_id', $input['plan_id'])->where('year_id', (string) $year->getKey())->exists()) {
            throw new ApiException('plan_unknown', 'Choose one of this year\'s fee plans.', 422);
        }
        if (($input['category_id'] ?? null) !== null && ! FeeCategory::query()->where('_id', $input['category_id'])->exists()) {
            throw new ApiException('category_unknown', 'Choose one of the school\'s categories.', 422);
        }
        $profile = StudentFee::query()->firstOrNew(['student_id' => $id, 'year_id' => (string) $year->getKey()]);
        $profile->fill([
            'plan_id' => $input['plan_id'] ?? null, 'category_id' => $input['category_id'] ?? null, 'joined_on' => $input['joined_on'] ?? null,
            'overrides' => array_map(fn ($o) => ['head_id' => $o['head_id'], 'from' => $o['from'] ?? null, 'to' => $o['to'] ?? null, 'amount_paise' => Money::parse($o['amount'])], $input['overrides'] ?? $profile->overrides),
        ])->save();

        return response()->json(['data' => self::profile($student, $profile)]);
    }

    public function raise(Request $request, DemandRaiser $raiser): JsonResponse
    {
        Gate::authorize('fees.setup');
        $year = AcademicYear::current() ?? throw new ApiException('no_current_year', 'There is no current academic year.', 422);
        $studentId = $request->validate(['student_id' => ['nullable', 'string', 'max:30']])['student_id'] ?? null;

        return response()->json(['data' => $raiser->raise($year, null, $studentId)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function planInput(Request $request, bool $creating): array
    {
        $r = $creating ? 'required' : 'sometimes';
        $input = $request->validate([
            'name' => [$r, 'string', 'min:2', 'max:80'],
            'grades' => [$r, 'array', 'min:1', 'max:20'], 'grades.*' => ['string', 'max:10'],
            'category_ids' => ['sometimes', 'array', 'max:20'], 'category_ids.*' => ['string', 'max:30'],
            'instalments' => [$r, 'array', 'min:1', 'max:24'],
            'instalments.*.label' => ['required', 'string', 'max:40'], 'instalments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'instalments.*.lines' => ['required', 'array', 'min:1', 'max:20'], 'instalments.*.lines.*.head_id' => ['required', 'string', 'max:30'],
            'instalments.*.lines.*.amount' => ['required', 'string', 'max:15'],
            'fine' => ['sometimes', 'array'], 'fine.mode' => ['required_with:fine', Rule::in(['none', 'flat', 'per_day'])],
            'fine.amount' => ['nullable', 'string', 'max:15'], 'fine.grace_days' => ['nullable', 'integer', 'min:0', 'max:90'], 'fine.cap' => ['nullable', 'string', 'max:15'],
            'discounts' => ['sometimes', 'array', 'max:10'], 'discounts.*.label' => ['required', 'string', 'max:40'],
            'discounts.*.condition' => ['required', Rule::in(['sibling', 'always'])], 'discounts.*.kind' => ['required', Rule::in(['percent', 'flat'])],
            'discounts.*.value' => ['required', 'string', 'max:15'], 'discounts.*.head_ids' => ['sometimes', 'array'], 'discounts.*.head_ids.*' => ['string', 'max:30'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
        ]);
        $heads = FeeHead::query()->get()->map(fn ($h) => (string) $h->getKey())->all();
        $out = array_intersect_key($input, array_flip(['name', 'grades', 'category_ids', 'status']));

        if (isset($input['instalments'])) {
            $keys = [];
            $out['instalments'] = array_map(function (array $i) use ($heads, &$keys): array {
                $key = Str::slug($i['label'].'-'.$i['due_on']);
                if (isset($keys[$key])) {
                    throw new ApiException('instalment_duplicate', 'Two instalments have the same name and date.', 422);
                }
                $keys[$key] = true;

                return ['key' => $key, 'label' => trim($i['label']), 'due_on' => $i['due_on'], 'lines' => array_map(function (array $l) use ($heads): array {
                    if (! in_array($l['head_id'], $heads, true)) {
                        throw new ApiException('head_unknown', 'Choose one of the school\'s fee heads.', 422);
                    }

                    return ['head_id' => $l['head_id'], 'amount_paise' => Money::parse($l['amount'])];
                }, $i['lines'])];
            }, $input['instalments']);
        }
        if (isset($input['fine'])) {
            $out['fine'] = [
                'mode' => $input['fine']['mode'], 'amount_paise' => isset($input['fine']['amount']) ? Money::parse($input['fine']['amount']) : 0,
                'grace_days' => (int) ($input['fine']['grace_days'] ?? 0), 'cap_paise' => isset($input['fine']['cap']) && $input['fine']['cap'] !== '' ? Money::parse($input['fine']['cap']) : null,
            ];
        }
        if (isset($input['discounts'])) {
            $out['discounts'] = array_map(fn (array $d) => [
                'key' => Str::slug($d['label']), 'label' => trim($d['label']), 'condition' => $d['condition'], 'kind' => $d['kind'],
                'value' => $d['kind'] === 'percent' ? Money::basisPoints($d['value']) : Money::parse($d['value']), 'head_ids' => array_values($d['head_ids'] ?? []),
            ], $input['discounts']);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function plan(FeePlan $p): array
    {
        return [
            'id' => (string) $p->getKey(), 'name' => $p->name, 'grades' => $p->grades, 'category_ids' => $p->category_ids, 'instalments' => $p->instalments,
            'fine' => $p->fine, 'discounts' => $p->discounts, 'status' => $p->status,
            'total_paise' => array_sum(array_map(fn ($i) => array_sum(array_column($i['lines'], 'amount_paise')), $p->instalments)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function profile(Student $student, ?StudentFee $profile): array
    {
        return ['student' => ['id' => (string) $student->getKey(), 'name' => $student->name], 'plan_id' => $profile?->plan_id, 'category_id' => $profile?->category_id,
            'joined_on' => $profile?->joined_on, 'overrides' => $profile->overrides ?? []];
    }
}
