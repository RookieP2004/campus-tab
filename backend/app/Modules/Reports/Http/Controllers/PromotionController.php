<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Fees\Domain\YearEndDues;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Domain\Promotion;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The year-end promotion wizard (year.promote: Chief, Principal).
 *
 *   POST /promotions/preview { to: {to_year_id} | {name, starts_on, ends_on}, exceptions: {student_id: action} }
 *   POST /promotions/carry-forward { to, decisions: {student_id: carry|leave}, dues_digest }
 *        the unpaid-dues step: one approval (step-up fees_carry_forward)
 *   POST /promotions/apply   { ...the same, digest }   one transaction; closes the old year
 *        (refused with dues_unresolved while an unpaid balance is undecided)
 */
class PromotionController
{
    public function __construct(private readonly Promotion $promotion) {}

    public function preview(Request $request): JsonResponse
    {
        $input = $this->validated($request);

        return response()->json(['data' => $this->promotion->preview($input['to'], $input['exceptions'] ?? [])]);
    }

    public function apply(Request $request): JsonResponse
    {
        $input = $this->validated($request, true);
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        return response()->json(['data' => $this->promotion->apply($user, $input['to'], $input['exceptions'] ?? [], (string) ($input['digest'] ?? ''))]);
    }

    public function carryForward(Request $request): JsonResponse
    {
        /** @var array{to: array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}, decisions?: array<string, string>, dues_digest: string} $input */
        $input = $request->validate([
            'to' => ['required', 'array'],
            'to.to_year_id' => ['nullable', 'string', 'max:30'],
            'to.name' => ['nullable', 'string', 'max:20'],
            'to.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'to.ends_on' => ['nullable', 'date_format:Y-m-d', 'after:to.starts_on'],
            'decisions' => ['sometimes', 'array', 'max:5000'],
            'decisions.*' => [Rule::in([YearEndDues::CARRY, YearEndDues::LEAVE])],
            'dues_digest' => ['required', 'string', 'size:64'],
        ]);
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        return response()->json(['data' => $this->promotion->carryForward($user, $input['to'], $input['decisions'] ?? [], $input['dues_digest'])]);
    }

    /**
     * @return array{to: array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}, exceptions?: array<string, string>, digest?: string}
     */
    private function validated(Request $request, bool $apply = false): array
    {
        /** @var array{to: array{to_year_id?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null}, exceptions?: array<string, string>, digest?: string} $input */
        $input = $request->validate([
            'to' => ['required', 'array'],
            'to.to_year_id' => ['nullable', 'string', 'max:30'],
            'to.name' => ['nullable', 'string', 'max:20'],
            'to.starts_on' => ['nullable', 'date_format:Y-m-d'],
            'to.ends_on' => ['nullable', 'date_format:Y-m-d', 'after:to.starts_on'],
            'exceptions' => ['sometimes', 'array', 'max:5000'],
            'exceptions.*' => [Rule::in(Promotion::ACTIONS)],
            'digest' => [$apply ? 'required' : 'sometimes', 'string', 'size:64'],
        ]);

        return $input;
    }
}
