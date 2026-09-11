<?php

namespace App\Support;

/**
 * For Livewire components that change one student: push that student's
 * freshly rendered grid row to the browser instead of re-rendering the grid.
 * The grid swaps the row in place (see students-grid.blade.php → patchRow).
 */
trait DispatchesGridRow
{
    protected function dispatchGridRow(?int $studentId, ?int $year = null): void
    {
        if (!$studentId) {
            return;
        }

        $payload = GridRow::payload($studentId, $year ?? (int) date('Y'));
        if ($payload) {
            $this->dispatch('grid-row-updated', ...$payload);
        }
    }
}
