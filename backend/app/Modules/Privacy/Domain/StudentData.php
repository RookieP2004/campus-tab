<?php

namespace App\Modules\Privacy\Domain;

use App\Modules\Fees\Models\FeeLedgerEntry;
use App\Modules\Identity\Models\User;
use App\Modules\People\Models\Consent;
use App\Modules\People\Models\Enrolment;
use App\Modules\People\Models\Guardian;
use App\Modules\People\Models\Student;
use App\Modules\Reports\Models\ReportCard;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * What the DPDP access and erasure requests do to a child's record.
 *
 * copy(): everything we hold about the child, readable, for an access
 * request (the office downloads it, watermarked, and hands it over).
 *
 * erase(): the child's personal details are removed: name, date of birth,
 * gender, ID numbers, the school's own custom fields, the sign-in, and a
 * guardian's contact details when that guardian has no other child here.
 * The record becomes "Erased student" with its admission number, so school
 * records that the law or the board require (attendance registers, marks,
 * report cards, fee receipts and ledger) still add up. The audit log and
 * the fee ledger are not touched (owner's decision 2026-11-28, decision
 * 0017). Consents stay, as proof of what the parent agreed to.
 */
class StudentData
{
    /** @return array<string, mixed> */
    public function copy(Student $student): array
    {
        $id = (string) $student->getKey();
        $guardians = Guardian::query()->whereIn('_id', $student->guardian_ids ?? [])->get();

        return [
            'student' => [
                'admission_no' => $student->admission_no, 'name' => $student->name, 'dob' => $student->dob, 'gender' => $student->gender,
                'ids' => $student->ids ?? [], 'custom' => $student->custom ?? [], 'login_name' => $student->login_name, 'status' => $student->status,
            ],
            'guardians' => $guardians->map(fn (Guardian $g) => ['name' => $g->name, 'relation' => $g->relation, 'phone' => $g->phone, 'email' => $g->email])->values()->all(),
            'enrolments' => Enrolment::query()->where('student_id', $id)->get()->map(fn (Enrolment $e) => ['year_id' => $e->year_id, 'section_id' => $e->section_id, 'roll_no' => $e->roll_no, 'result' => $e->result])->values()->all(),
            'consents' => Consent::query()->where('student_id', $id)->orderBy('created_at')->get()
                ->map(fn (Consent $c) => ['purpose' => $c->purpose, 'given' => $c->given, 'at' => $c->at, 'by_parent' => $c->method === Consent::PARENT_ACCOUNT, 'notice_version' => $c->notice_version])->values()->all(),
            'report_cards' => ReportCard::query()->where('student_id', $id)->where('status', ReportCard::PUBLISHED)->get()
                ->map(fn (ReportCard $c) => ['year_id' => $c->year_id, 'version' => $c->version, 'result' => $c->snapshot['overall'] ?? null])->values()->all(),
            'fees' => FeeLedgerEntry::query()->where('student_id', $id)->orderBy('created_at')->get()
                ->map(fn (FeeLedgerEntry $e) => ['year_id' => $e->year_id, 'type' => $e->type, 'amount_paise' => $e->amount_paise, 'at' => $e->created_at?->toIso8601String()])->values()->all(),
            'kept_by_the_school' => 'Attendance registers, marks and the audit log also mention this child; they are school records and are listed here by kind only.',
        ];
    }

    /** @return array{student: string, guardians_cleared: int} */
    public function erase(Student $student): array
    {
        if ($student->status === 'erased') {
            throw new ApiException('already_erased', 'This record was already erased.', 409);
        }

        return DB::connection('mongodb')->transaction(function () use ($student): array {
            $cleared = 0;
            foreach (Guardian::query()->whereIn('_id', $student->guardian_ids ?? [])->get() as $guardian) {
                $others = array_values(array_diff($guardian->student_ids ?? [], [(string) $student->getKey()]));
                if ($others !== []) {
                    $guardian->fill(['student_ids' => $others])->save();

                    continue;
                }
                if ($guardian->user_id !== null) {
                    User::query()->find($guardian->user_id)?->forceFill(['status' => User::STATUS_INACTIVE, 'name' => 'Erased parent', 'email' => null, 'phone' => null])->save();
                }
                $guardian->fill(['name' => 'Erased guardian', 'phone' => null, 'email' => null, 'relation' => null, 'student_ids' => []])->save();
                $cleared++;
            }
            if ($student->user_id !== null) {
                User::query()->find($student->user_id)?->forceFill(['status' => User::STATUS_INACTIVE, 'name' => 'Erased student'])->save();
            }
            $student->forceFill([
                'name' => 'Erased student', 'dob' => null, 'gender' => null, 'ids' => [], 'custom' => [],
                'login_name' => null, 'user_id' => null, 'guardian_ids' => [], 'status' => 'erased',
            ])->save();

            return ['student' => (string) $student->admission_no, 'guardians_cleared' => $cleared];
        }, 5);
    }
}
