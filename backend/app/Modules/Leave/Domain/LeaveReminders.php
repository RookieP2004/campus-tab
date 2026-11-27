<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Leave\Models\LeaveRequest;

/**
 * Nudges for requests left waiting (school config policies.leave):
 *
 *  - after remind_after_hours (24) at a stage, the people who can act there
 *    get a reminder;
 *  - after escalate_after_hours (48), the level above is told as well
 *    (LeaveRouting::escalationFor). It never changes who decides.
 *
 * Each happens once per stage (reminded_for / escalated_for hold the stage).
 * Run every hour by the scheduler (leave:remind).
 */
class LeaveReminders
{
    public function __construct(
        private readonly LeaveSettings $settings,
        private readonly LeaveRouting $routing,
        private readonly LeaveWorkflow $workflow,
    ) {}

    /**
     * @return array{reminded: int, escalated: int}
     */
    public function run(): array
    {
        $policy = $this->settings->policy();
        $now = now();
        $reminded = 0;
        $escalated = 0;

        $waiting = LeaveRequest::query()->whereIn('status', LeaveRequest::PENDING)->whereNotNull('stage_since')
            ->where('stage_since', '<=', $now->copy()->subHours($policy['remind_after_hours']))->get();

        foreach ($waiting as $request) {
            $stage = (string) $request->stage;

            if ($request->reminded_for !== $stage) {
                $this->workflow->notify($this->routing->actorsNow($request), $request, 'leave.waiting', 'leave.notifications.reminder');
                $request->forceFill(['reminded_for' => $stage])->save();
                $reminded++;
            }

            if ($request->escalated_for !== $stage && $request->stage_since !== null && $request->stage_since->lessThanOrEqualTo($now->copy()->subHours($policy['escalate_after_hours']))) {
                $this->workflow->notify($this->routing->escalationFor($request), $request, 'leave.waiting', 'leave.notifications.escalated');
                $request->forceFill(['escalated_for' => $stage])->save();
                $escalated++;
            }
        }

        return ['reminded' => $reminded, 'escalated' => $escalated];
    }
}
