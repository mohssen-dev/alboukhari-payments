<?php
/**
 * End-to-end test for the event-driven modal + panel architecture.
 * Verifies: grid dispatches events, modals/panel listen and open correctly.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Livewire\StudentsGrid;
use App\Livewire\PaymentModal;
use App\Livewire\FamilyModal;
use App\Livewire\SendSingleMessage;
use App\Livewire\StudentPanel;
use Livewire\Livewire;
use Illuminate\Support\Facades\Auth;

Auth::loginUsingId(1);

$totalMs = 0;
$totalTests = 0;
$failures = 0;

function step(string $label, callable $fn): void
{
    global $totalMs, $totalTests, $failures;
    echo "  " . str_pad($label, 62);
    $t = microtime(true);
    try {
        $fn();
        $ms = (microtime(true) - $t) * 1000;
        $totalMs += $ms;
        $totalTests++;
        printf("  OK  %6.1fms\n", $ms);
    } catch (\Throwable $e) {
        $failures++;
        echo "  FAIL: " . $e->getMessage() . "\n";
        echo "    at " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

echo "=== Scenario 1: Grid click cell → dispatches event, does NOT re-render ===\n";
$grid = null;
step('Mount StudentsGrid', function () use (&$grid) { $grid = Livewire::test(StudentsGrid::class); });
step('Call openPayment(43, 5) — should be fast (no re-render)', function () use (&$grid) {
    $grid->call('openPayment', 43, 5);
    $grid->assertDispatched('open-payment-modal', studentId: 43, year: 2026, month: 5);
});
step('Call openStudent(43) — should dispatch open-student-panel', function () use (&$grid) {
    $grid->call('openStudent', 43);
    $grid->assertDispatched('open-student-panel', studentId: 43);
});
step('Call openFamily(43) — should dispatch open-family-modal', function () use (&$grid) {
    $grid->call('openFamily', 43);
    $grid->assertDispatched('open-family-modal', studentId: 43);
});

echo "\n=== Scenario 2: PaymentModal listens for open event ===\n";
$modal = null;
step('Mount empty PaymentModal (no initial student)', function () use (&$modal) {
    $modal = Livewire::test(PaymentModal::class);
    $modal->assertSet('isOpen', false);
    $modal->assertSet('studentId', null);
});
step('Dispatch open-payment-modal event → modal opens', function () use (&$modal) {
    $modal->dispatch('open-payment-modal', studentId: 43, year: 2026, month: 5);
    $modal->assertSet('isOpen', true);
    $modal->assertSet('studentId', 43);
    if (empty($modal->get('studentName'))) throw new RuntimeException('studentName not populated');
});
step('Save payment → dispatches payment-saved event', function () use (&$modal) {
    $modal->set('amount', 30)->set('method', 'cash')->set('paid_at', date('Y-m-d'))
          ->call('save', false);
    $modal->assertDispatched('payment-saved');
});
step('Cleanup test payment', function () {
    App\Models\Payment::where('student_id', 43)->where('period_year', 2026)
        ->where('period_month', 5)->where('method', 'cash')->where('amount', 30)->delete();
});

echo "\n=== Scenario 3: FamilyModal listens for open event ===\n";
$fm = null;
step('Mount empty FamilyModal', function () use (&$fm) {
    $fm = Livewire::test(FamilyModal::class);
    $fm->assertSet('isOpen', false);
});
step('Dispatch open-family-modal → modal populates', function () use (&$fm) {
    $fm->dispatch('open-family-modal', studentId: 43);
    $fm->assertSet('isOpen', true);
});
step('Close family modal', function () use (&$fm) {
    $fm->call('close');
    $fm->assertSet('isOpen', false);
});

echo "\n=== Scenario 4: SendSingleMessage listens for open event ===\n";
$sm = null;
step('Mount empty SendSingleMessage', function () use (&$sm) {
    $sm = Livewire::test(SendSingleMessage::class);
    $sm->assertSet('isOpen', false);
});
step('Dispatch open-send-message → opens', function () use (&$sm) {
    $sm->dispatch('open-send-message', studentId: 43);
    $sm->assertSet('isOpen', true);
    if (empty($sm->get('studentName'))) throw new RuntimeException('studentName empty');
});

echo "\n=== Scenario 5: StudentPanel handles empty + open events ===\n";
$panel = null;
step('Mount empty StudentPanel (null studentId)', function () use (&$panel) {
    $panel = Livewire::test(StudentPanel::class);
    $panel->assertSet('studentId', null);
    // Should render empty view without errors
    $html = $panel->html();
    if (str_contains($html, 'exception') || str_contains($html, 'Trying to get')) {
        throw new RuntimeException('empty render errored');
    }
});
step('Dispatch open-student-panel → panel loads student', function () use (&$panel) {
    $panel->dispatch('open-student-panel', studentId: 43);
    $panel->assertSet('studentId', 43);
    $name = $panel->get('name');
    if (empty($name)) throw new RuntimeException('name not loaded');
});
step('closeSelf() → studentId reset to null', function () use (&$panel) {
    $panel->call('closeSelf');
    $panel->assertSet('studentId', null);
});

echo "\n=== Scenario 6: Advance payment (future month) ===\n";
$modal = Livewire::test(PaymentModal::class);
step('Open modal for month 12 (future) via event', function () use (&$modal) {
    $modal->dispatch('open-payment-modal', studentId: 43, year: 2026, month: 12);
    $modal->assertSet('isOpen', true);
    $modal->assertSet('month', 12);
});
step('Save advance payment', function () use (&$modal) {
    $modal->set('amount', 30)->set('method', 'cash')->set('paid_at', date('Y-m-d'))
          ->call('save', false);
});
step('Advance payment exists in DB', function () {
    $c = App\Models\Payment::where('student_id', 43)->where('period_year', 2026)
        ->where('period_month', 12)->where('method', 'cash')->count();
    if ($c === 0) throw new RuntimeException('advance not saved');
});
step('Cleanup advance payment', function () {
    App\Models\Payment::where('student_id', 43)->where('period_year', 2026)
        ->where('period_month', 12)->where('method', 'cash')->delete();
});

echo "\n=== Scenario 7: openPayment TIMING (perf-critical) ===\n";
$grid = Livewire::test(StudentsGrid::class);
step('First openPayment (warm)', function () use (&$grid) { $grid->call('openPayment', 43, 5); });
step('Second openPayment (should be fast — skipRender)', function () use (&$grid) { $grid->call('openPayment', 44, 6); });
step('Third openPayment', function () use (&$grid) { $grid->call('openPayment', 45, 7); });

echo "\n=== Scenario 8: OPEN → CLOSE → OPEN (the failing bug) ===\n";
$modal = Livewire::test(PaymentModal::class);
step('Open modal for student 43 month 5', function () use (&$modal) {
    $modal->dispatch('open-payment-modal', studentId: 43, year: 2026, month: 5);
    $modal->assertSet('isOpen', true);
});
step('Call close() — state should reset', function () use (&$modal) {
    $modal->call('close');
    $modal->assertSet('isOpen', false);
    $modal->assertSet('studentId', null);
    $modal->assertSet('amount', null);
});
step('Re-open modal for a DIFFERENT student', function () use (&$modal) {
    $modal->dispatch('open-payment-modal', studentId: 44, year: 2026, month: 6);
    $modal->assertSet('isOpen', true);
    $modal->assertSet('studentId', 44);
    $modal->assertSet('month', 6);
});
step('Close again — state clean', function () use (&$modal) {
    $modal->call('close');
    $modal->assertSet('isOpen', false);
});
step('Third open — no residual state', function () use (&$modal) {
    $modal->dispatch('open-payment-modal', studentId: 45, year: 2026, month: 7);
    $modal->assertSet('studentId', 45);
    $modal->assertSet('editingPaymentId', null);
});

echo "\n=== Scenario 9: FamilyModal open→close→open ===\n";
$fm = Livewire::test(FamilyModal::class);
step('Open family modal for 43', function () use (&$fm) {
    $fm->dispatch('open-family-modal', studentId: 43);
    $fm->assertSet('isOpen', true);
});
step('Close', function () use (&$fm) {
    $fm->call('close');
    $fm->assertSet('isOpen', false);
    $fm->assertSet('studentId', null);
});
step('Re-open for different student', function () use (&$fm) {
    $fm->dispatch('open-family-modal', studentId: 44);
    $fm->assertSet('isOpen', true);
    $fm->assertSet('studentId', 44);
});

echo "\n=== Scenario 10: StudentPanel open→close→open ===\n";
$panel = Livewire::test(StudentPanel::class);
step('Open panel for 43', function () use (&$panel) {
    $panel->dispatch('open-student-panel', studentId: 43);
    $panel->assertSet('studentId', 43);
});
step('closeSelf', function () use (&$panel) {
    $panel->call('closeSelf');
    $panel->assertSet('studentId', null);
});
step('Re-open for 44', function () use (&$panel) {
    $panel->dispatch('open-student-panel', studentId: 44);
    $panel->assertSet('studentId', 44);
});
step('Re-render doesn\'t error', function () use (&$panel) {
    $html = $panel->html();
    if (str_contains($html, 'Trying to') || str_contains($html, 'Exception')) {
        throw new RuntimeException('render error: ' . substr($html, 0, 300));
    }
});

echo "\n=== Scenario 11: FamilyModal payNow chains to PaymentModal ===\n";
$fm = Livewire::test(FamilyModal::class);
step('Open family modal', function () use (&$fm) { $fm->dispatch('open-family-modal', studentId: 43); });
step('Call payNow(50, 7) — should close self + dispatch open-payment-modal', function () use (&$fm) {
    $fm->call('payNow', 50, 7);
    $fm->assertSet('isOpen', false);
    $fm->assertDispatched('open-payment-modal', studentId: 50, year: 2026, month: 7);
});

echo "\n=== Scenario 12: FamilyModal viewStudent chains to StudentPanel ===\n";
$fm = Livewire::test(FamilyModal::class);
step('Open family modal', function () use (&$fm) { $fm->dispatch('open-family-modal', studentId: 43); });
step('Call viewStudent(50) — should close self + dispatch open-student-panel', function () use (&$fm) {
    $fm->call('viewStudent', 50);
    $fm->assertSet('isOpen', false);
    $fm->assertDispatched('open-student-panel', studentId: 50);
});

echo "\n---\n";
printf("Tests: %d passed, %d failed, total: %.1fms\n", $totalTests - $failures, $failures, $totalMs);
exit($failures > 0 ? 1 : 0);
