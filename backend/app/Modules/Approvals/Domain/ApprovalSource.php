<?php

namespace App\Modules\Approvals\Domain;

use App\Modules\Identity\Models\User;

/**
 * One kind of item that can wait on someone's decision (leave, mark
 * corrections, later fee adjustments). Each module decides on the server
 * which of its items this person may act on, and fills them only with what
 * they may see. Sources are listed in config/approvals.php.
 *
 * An item is:
 *   kind      leave | marks | fees | staff
 *   id        the record's id
 *   title     who or what, e.g. "Anjali Verma · Casual leave"
 *   detail    one line, e.g. "3–4 Dec · 2 days · verified by Clerk", or null
 *   subtitle  facts the screen turns into that line in the reader's language
 *   status    the record's state
 *   at        when it started waiting (ISO 8601)
 *   link      the screen that decides it
 *   flags     e.g. ["fast_track"], ["self_approved"]
 */
interface ApprovalSource
{
    public function kind(): string;

    /**
     * @return list<array{kind: string, id: string, title: string, detail: string|null, subtitle?: array<string, mixed>, status: string, at: string|null, link: string, flags: list<string>}>
     */
    public function itemsFor(User $user): array;
}
