<?php

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\AcademicStructure\Models\AcademicYear;
use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Fees\Domain\Dues;
use App\Modules\Fees\Domain\FeeAccess;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Fees\Models\ReceiptCancellation;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Student;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MongoDB\BSON\Regex;

/**
 * Reading fees.
 *
 *   GET /students/{id}/fees          dues, what is coming, ledger, receipts (office, the parent, the student if allowed)
 *   GET /fees/sections/{id}/status   paid or pending per student, no amounts (class teacher)
 *   GET /receipts/{id}               one receipt, printable (office, the family)
 */
class StudentFeesController
{
    public function __construct(private readonly Dues $dues, private readonly FeeAccess $access) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $user = self::user($request);
        $student = Student::query()->find($id);
        if (! $student instanceof Student) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
        Gate::authorize('fees.view-student', $student);
        $office = $this->access->office($user);

        return response()->json(['data' => $this->dues->forStudent($student, $office) + ['can' => [
            'collect' => Gate::allows('fees.collect'),
            'adjust' => Gate::allows('fees.adjust-propose'),
            'pay_online' => Gate::allows('fees.pay', $student),
            'setup' => Gate::allows('fees.setup'),
        ]]]);
    }

    /**
     * GET /fees/students?q= — the counter's student search (name or
     * admission number): the Fee Collector duty has no other student access.
     */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('fees.office');
        $q = trim((string) ($request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']));
        $students = Student::query()->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'regex', new Regex(preg_quote($q), 'i'))->orWhere('admission_no', 'regex', new Regex('^'.preg_quote($q), 'i')))
            ->limit(10)->get();
        $year = AcademicYear::current();
        $enrolments = $year ? Enrolment::query()->where('year_id', (string) $year->getKey())->whereIn('student_id', $students->map(fn ($s) => (string) $s->getKey())->all())->get()->keyBy('student_id') : collect();
        $sections = Section::query()->whereIn('_id', $enrolments->pluck('section_id')->filter()->values()->all())->get()->keyBy(fn ($s) => (string) $s->getKey());

        return response()->json(['data' => $students->map(fn (Student $s) => [
            'id' => (string) $s->getKey(), 'name' => $s->name, 'admission_no' => $s->admission_no,
            'section_label' => $sections[$enrolments[(string) $s->getKey()]->section_id ?? '']->label ?? null,
        ])->values()]);
    }

    public function sectionStatus(Request $request, string $id): JsonResponse
    {
        $section = Section::query()->find($id);
        if (! $section instanceof Section) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
        Gate::authorize('fees.section-status', $section);

        return response()->json(['data' => ['section' => ['id' => $id, 'label' => $section->label], 'students' => $this->dues->sectionStatus($section)]]);
    }

    public function receipt(Request $request, string $id): JsonResponse
    {
        $user = self::user($request);
        $receipt = Receipt::query()->find($id);
        $student = $receipt instanceof Receipt ? Student::query()->find($receipt->student_id) : null;
        if (! $receipt instanceof Receipt || ! $student instanceof Student || ! $this->access->mayViewStudent($user, $student)) {
            throw new ApiException('not_found', 'The record was not found.', 404);
        }
        $payment = Payment::query()->find($receipt->payment_id);
        $pending = ReceiptCancellation::query()->where('receipt_id', (string) $receipt->getKey())->whereIn('status', ['requested', 'proposed'])->exists();

        return response()->json(['data' => Dues::receipt($receipt, $this->access->office($user)) + [
            'reference' => $payment?->reference, 'bank' => $payment?->bank, 'payment_status' => $payment?->status,
            'cancellation_pending' => $pending,
            'can' => ['cancel' => $receipt->status === 'valid' && ! $pending && Gate::allows('fees.cancel-request'), 'clear' => $payment?->status === Payment::PENDING && Gate::allows('fees.collect')],
        ]]);
    }

    public static function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiException('school_account_required', 'Sign in with a school account to continue.', 403);
        }

        return $user;
    }
}
