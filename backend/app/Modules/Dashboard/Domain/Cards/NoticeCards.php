<?php

namespace App\Modules\Dashboard\Domain\Cards;

use App\Modules\Communication\Domain\Notices;
use App\Modules\Dashboard\Domain\CardProvider;
use App\Modules\Dashboard\Domain\DashboardContext;
use App\Modules\Identity\Models\User;

/** The latest notices this person may read (NoticeAudience decides). */
class NoticeCards implements CardProvider
{
    public function __construct(private readonly Notices $notices) {}

    public function cards(User $user, DashboardContext $context): array
    {
        if (! $context->can('notices.view')) {
            return [];
        }

        $items = $this->notices->listFor($user, 4);

        return [[
            'id' => 'notices', 'kind' => 'notices', 'title' => 'dashboard.cards.notices', 'priority' => 30, 'link' => '/notices',
            'data' => [
                'unread' => count(array_filter($items, fn (array $n) => ! $n['read'])),
                'can_send' => $context->can('notices.send'),
                'items' => array_map(fn (array $n) => ['id' => $n['id'], 'label' => $n['title'], 'by' => $n['sent_by'], 'at' => $n['published_at'], 'read' => $n['read'], 'link' => '/notices/'.$n['id']], $items),
            ],
        ]];
    }
}
