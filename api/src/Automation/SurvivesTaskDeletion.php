<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * Marks an action that still means something once its task is gone (#764).
 *
 * Almost every action edits the task — assign it, tag it, move it. On
 * `task.deleted` there is nothing left to edit, so running them would either
 * throw or silently mutate a detached object nobody will save. Both are worse
 * than refusing.
 *
 * A marker interface rather than a method on {@see ActionDefinitionInterface}
 * because the safe case is the rare one: the default should be "needs a live
 * task", and opting in should be a deliberate act by the two or three actions
 * that genuinely don't.
 */
interface SurvivesTaskDeletion
{
}
