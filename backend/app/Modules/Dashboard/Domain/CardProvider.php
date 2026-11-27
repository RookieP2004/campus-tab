<?php

namespace App\Modules\Dashboard\Domain;

use App\Modules\Identity\Models\User;

/**
 * One area's dashboard cards. A provider decides on the server which cards
 * this person gets, from their permissions and scopes, and fills them only
 * with what they may see. The front end never filters.
 *
 * A card is:
 *   id        stable name, e.g. "licence"
 *   kind      licence | kpi | todo | list | action | banner | children | notices
 *   title     an i18n key (the screen translates it), with params
 *   priority  lower comes first; the role's "top of the screen" card is 0
 *   link      a screen path, or null
 *   data      the card's numbers or rows
 *
 * Providers are listed in config/dashboard.php; each phase adds its own.
 */
interface CardProvider
{
    /**
     * @return list<array{id: string, kind: string, title: string, params?: array<string, mixed>, priority: int, link: string|null, data: array<string, mixed>}>
     */
    public function cards(User $user, DashboardContext $context): array;
}
