<?php

namespace App\Listeners;

use App\Models\JobRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Str;
use Throwable;

/**
 * Records every scheduled-task run into `job_runs` so the /ops Platform Health
 * page can show the nightly pipeline (last run, status, duration) per command.
 * Best-effort — recording must never affect the task itself.
 */
class RecordScheduledTaskRun
{
    public function finished(ScheduledTaskFinished $event): void
    {
        $runtime = property_exists($event, 'runtime') ? $event->runtime : null;
        $this->record($event->task, JobRun::STATUS_SUCCESS, $runtime, null);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $message = null;
        try {
            $ex = $event->exception ?? null;
            $message = $ex instanceof Throwable ? $ex->getMessage() : null;
        } catch (Throwable $e) {
            // ignore
        }

        $this->record($event->task, JobRun::STATUS_FAILED, null, $message);
    }

    private function record($task, string $status, $runtimeSeconds, ?string $message): void
    {
        try {
            JobRun::create([
                'command'     => $this->taskName($task),
                'status'      => $status,
                'duration_ms' => is_numeric($runtimeSeconds) ? (int) round(((float) $runtimeSeconds) * 1000) : null,
                'message'     => $message ? Str::limit($message, 500) : null,
                'ran_at'      => now(),
            ]);
        } catch (Throwable $e) {
            // best-effort
        }
    }

    /** Reduce a scheduler task to a readable name (the artisan command, ideally). */
    private function taskName($task): string
    {
        $command = is_object($task) && isset($task->command) ? (string) $task->command : '';

        if ($command !== '' && preg_match('/artisan[\'"]?\s+(\S+)/', $command, $m)) {
            return $m[1];
        }

        $description = is_object($task) && isset($task->description) ? (string) $task->description : '';
        if ($description !== '') {
            return $description;
        }

        return $command !== '' ? Str::limit($command, 80) : 'scheduled-task';
    }
}
