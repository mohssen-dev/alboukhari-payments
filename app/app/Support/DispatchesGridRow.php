<?php

namespace App\Support;

/**
 * For Livewire components that change one student: push that student's
 * freshly rendered grid row to the browser instead of re-rendering the grid.
 * The grid swaps the row in place (see students-grid.blade.php → patchRow).
 */
trait DispatchesGridRow
{
    /**
     * @param string $scope 'year'    — only that year's months changed (a payment):
     *                                  a grid showing another year ignores it;
     *                                  'student' — the student itself changed (name,
     *                                  phone, flags): a grid showing another year
     *                                  re-renders so the row stays correct.
     */
    protected function dispatchGridRow(?int $studentId, ?int $year = null, string $scope = 'student'): void
    {
        if (!$studentId) {
            return;
        }

        $payload = GridRow::payload($studentId, $year ?? (int) date('Y'));
        if ($payload) {
            $this->dispatch('grid-row-updated', ...$payload, scope: $scope);
        }
    }
}
