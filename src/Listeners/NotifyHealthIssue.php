<?php

namespace PragmaRX\Health\Listeners;

use Illuminate\Support\Facades\Notification;
use PragmaRX\Health\Events\RaiseHealthIssue;
use ReflectionClass;
use ReflectionException;

class NotifyHealthIssue
{
    /**
     * @return static
     */
    private function getNotifiableUsers()
    {
        return collect(config('health.notifications.users.emails'))->map(
            function ($item) {
                $model = instantiate(
                    config('health.notifications.users.model')
                );

                $model->email = $item;

                return $model;
            }
        );
    }

    /**
     * Handle the event.
     *
     * @param RaiseHealthIssue $event
     * @return void
     * @throws ReflectionException
     */
    public function handle(RaiseHealthIssue $event)
    {
        $notifier = config('health.notifications.notifier') ?: 'PragmaRX\Health\Notifications\HealthStatus';
        $notifierClass = new ReflectionClass($notifier );
        try {
            $event->failure->targets->each(function ($target) use ($event, &$notifierClass) {
                if (! $target->result->healthy) {
                    Notification::send(
                        $this->getNotifiableUsers(),
                        $notifierClass->newInstance($target, $event->channel)
                    );
                }
            });
        } catch (\Exception $exception) {
            report($exception);
        } catch (\Throwable $error) {
            report($error);
        }
    }
}
