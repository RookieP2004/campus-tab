<?php

namespace App\Modules\Identity\Domain\Scopes;

/**
 * A record a permission can be checked against. Later phases implement it on
 * students, attendance days, papers and the rest. Every key is optional:
 *
 *   owner_ids    users the record belongs to                (own)
 *   student_ids  students the record is about               (child)
 *   section_id   the section it belongs to                  (class)
 *   subject_key  "<section_id>:<subject_id>"                (subject)
 *   wing         the wing of its section, e.g. "primary"    (wing)
 */
interface AccessSubject
{
    /**
     * @return array{owner_ids?: list<string>, student_ids?: list<string>, section_id?: string|null, subject_key?: string|null, wing?: string|null}
     */
    public function accessFacts(): array;
}
