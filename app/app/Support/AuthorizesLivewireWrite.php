<?php

namespace App\Support;

/**
 * Guard for Livewire write methods. Route middleware alone is insufficient
 * because persistent modals (PaymentModal, StudentPanel, SendSingleMessage)
 * are mounted in the layout — so their methods are reachable from ANY
 * authenticated page, including pages viewers can see (/reports, /campaigns).
 */
trait AuthorizesLivewireWrite
{
    protected function assertCanWrite(): void
    {
        if (! auth()->user()?->canWrite()) {
            abort(403);
        }
    }

    protected function assertAdmin(): void
    {
        if (! auth()->user()?->isAdmin()) {
            abort(403);
        }
    }
}
