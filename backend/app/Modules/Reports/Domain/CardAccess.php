<?php

namespace App\Modules\Reports\Domain;

use App\Modules\AcademicStructure\Models\Section;
use App\Modules\Identity\Domain\AccessControl;
use App\Modules\Identity\Domain\Scopes\ClassScope;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Models\ReportCard;

/**
 * Who sees which report card.
 *
 *  - Staff: whoever makes and publishes cards for the section
 *    (results.publish: Principal, Chief, a Vice Principal's wing), its class
 *    teacher (remarks.edit at class scope), and its subject teachers (their
 *    own subjects only: the other subjects are cut from what they get).
 *  - Families (results.view at child or own scope): only published versions
 *    (current or replaced) of their own children's cards (owner's rule 4).
 */
class CardAccess
{
    public function __construct(private readonly AccessControl $access) {}

    public function staffSeesWhole(User $user, ReportCard|Section $subject): bool
    {
        $probe = $subject instanceof Section ? ReportCards::sectionProbe($subject) : ReportCards::classProbe($subject);

        return $this->access->allows($user, 'results.publish', $probe)
            || $this->access->holdsAt($user, 'results.view', 'school')
            || ($this->access->holdsAt($user, 'remarks.edit', 'class') && app(ClassScope::class)->covers($user, $probe));
    }

    /**
     * The subjects of this section the person teaches (subject scope).
     *
     * @return list<string> subject ids
     */
    public function ownSubjects(User $user, string $sectionId): array
    {
        if (! $this->access->holdsAt($user, 'remarks.edit', 'subject')) {
            return [];
        }
        $out = [];
        foreach ($user->accessScope()['subject_sections'] ?? [] as $key) {
            [$section, $subject] = array_pad(explode(':', (string) $key, 2), 2, '');
            if ($section === $sectionId && $subject !== '') {
                $out[] = $subject;
            }
        }

        return $out;
    }

    public function mayView(User $user, ReportCard $card): bool
    {
        if ($this->staffSeesWhole($user, $card) || $this->ownSubjects($user, $card->section_id) !== []) {
            return true;
        }

        return $card->isPublic() && $this->access->allows($user, 'results.view', $card);
    }

    public function mayViewSection(User $user, Section $section): bool
    {
        return $this->staffSeesWhole($user, $section) || $this->ownSubjects($user, (string) $section->getKey()) !== [];
    }

    /**
     * What this person gets of a card.
     *
     * @return array<string, mixed>
     */
    public function present(User $user, ReportCard $card, ReportCards $cards): array
    {
        $whole = $this->staffSeesWhole($user, $card);
        $family = ! $whole && $this->ownSubjects($user, $card->section_id) === [];
        $own = $whole || $family ? null : $this->ownSubjects($user, $card->section_id);
        $snapshot = $card->snapshot;
        if ($own !== null) {
            $snapshot['subjects'] = array_values(array_filter($snapshot['subjects'] ?? [], fn (array $s) => in_array($s['subject_id'], $own, true)));
            unset($snapshot['overall']);
        }
        $remarks = $card->remarks;
        $remarkClass = in_array($card->status, [ReportCard::DRAFT, ReportCard::REVIEWED], true) && $cards->mayRemarkClass($user, $card);
        if ($own !== null) {
            $remarks = ['subjects' => array_intersect_key($remarks['subjects'] ?? [], array_flip($own))];
        }

        return [
            'id' => (string) $card->getKey(),
            'student' => ['id' => $card->student_id, 'name' => $card->student_name],
            'section' => ['id' => $card->section_id, 'label' => $card->section_label],
            'year' => ['id' => $card->year_id, 'name' => $card->year_name],
            'version' => $card->version,
            'status' => $card->status,
            'current' => $card->status !== ReportCard::REPLACED,
            'replaced_by_id' => $card->replaced_by_id,
            'issued_at' => $card->issued_at?->toIso8601String(),
            'snapshot' => $family ? ['overall' => $snapshot['overall'] ?? null] : $snapshot,
            'remarks' => $family ? [] : $remarks,
            'co_scholastic' => $family || $own !== null ? [] : $card->co_scholastic,
            // The areas and grades the class teacher chooses from (only for whoever may set them).
            'co_scholastic_options' => $remarkClass ? config('reports.co_scholastic') : null,
            'attendance' => $family || $own !== null ? null : $card->attendance,
            'reissue_reason' => $whole ? $card->reissue_reason : null,
            'has_pdf' => $card->pdf !== null,
            'history' => $whole ? $card->history : [],
            'can' => [
                'remark_class' => $remarkClass,
                'remark_subjects' => in_array($card->status, [ReportCard::DRAFT, ReportCard::REVIEWED], true) ? ($own ?? ($whole ? array_column($snapshot['subjects'] ?? [], 'subject_id') : [])) : [],
                'review' => $card->status === ReportCard::DRAFT && $cards->mayManage($user, Section::query()->findOrFail($card->section_id)),
                'reissue' => $card->status === ReportCard::PUBLISHED && $cards->mayReissue($user),
                'download' => $card->pdf !== null,
            ],
        ];
    }
}
