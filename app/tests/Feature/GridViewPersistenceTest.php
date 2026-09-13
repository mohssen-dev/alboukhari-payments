<?php

namespace Tests\Feature;

use App\Livewire\StudentsGrid;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The grid's filter bar survives a reload (a year-long cookie), and focus
 * mode can add a student and then shows it.
 */
class GridViewPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user(User::ROLE_ADMIN));
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => $role,
            'email' => $role . '@grid-view.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function savedView(): array
    {
        $this->assertTrue(Cookie::hasQueued(StudentsGrid::VIEW_COOKIE), 'the choice was not remembered');

        return json_decode(Cookie::queued(StudentsGrid::VIEW_COOKIE)->getValue(), true);
    }

    public function test_without_a_saved_view_the_grid_opens_on_this_year_and_100_rows(): void
    {
        Livewire::test(StudentsGrid::class)
            ->assertSet('year', (int) date('Y'))
            ->assertSet('perPage', 100)
            ->assertSet('filterStatus', 'all')
            ->assertSet('clientFilter', 'all');
    }

    public function test_a_saved_view_is_restored_on_the_next_page_load(): void
    {
        $next = (int) date('Y') + 1;

        Livewire::withCookie(StudentsGrid::VIEW_COOKIE, json_encode([
            'year' => $next, 'perPage' => 500, 'filterStatus' => 'hidden', 'clientFilter' => 'overdue',
        ]))->test(StudentsGrid::class)
            ->assertSet('year', $next)
            ->assertSet('perPage', 500)
            ->assertSet('filterStatus', 'hidden')
            ->assertSet('clientFilter', 'overdue');
    }

    public function test_focus_mode_restores_the_same_view(): void
    {
        Livewire::withCookie(StudentsGrid::VIEW_COOKIE, json_encode(['year' => 2024, 'perPage' => 200]))
            ->test(StudentsGrid::class, ['focus' => true])
            ->assertSet('focus', true)
            ->assertSet('year', 2024)
            ->assertSet('perPage', 200);
    }

    public function test_a_broken_or_crafted_saved_view_falls_back_to_the_defaults(): void
    {
        Livewire::withCookie(StudentsGrid::VIEW_COOKIE, json_encode([
            'year' => 1999, 'perPage' => 100000, 'filterStatus' => 'everything', 'clientFilter' => '<script>',
        ]))->test(StudentsGrid::class)
            ->assertSet('year', (int) date('Y'))
            ->assertSet('perPage', 100)
            ->assertSet('filterStatus', 'all')
            ->assertSet('clientFilter', 'all');

        Livewire::withCookie(StudentsGrid::VIEW_COOKIE, 'not json')
            ->test(StudentsGrid::class)
            ->assertSet('perPage', 100);
    }

    public function test_changing_year_rows_or_state_is_remembered(): void
    {
        $next = (int) date('Y') + 1;

        Livewire::test(StudentsGrid::class)
            ->set('year', $next)
            ->set('perPage', 500)
            ->set('filterStatus', 'blocked');

        $this->assertSame(
            ['year' => $next, 'perPage' => 500, 'filterStatus' => 'blocked', 'clientFilter' => 'all'],
            $this->savedView(),
        );
    }

    public function test_rows_per_page_outside_the_picker_is_refused(): void
    {
        Livewire::test(StudentsGrid::class)
            ->set('perPage', 100000)
            ->assertSet('perPage', 100);
    }

    public function test_the_client_filter_is_remembered_without_re_rendering_the_grid(): void
    {
        Livewire::test(StudentsGrid::class)
            ->call('rememberClientFilter', 'with_siblings')
            ->assertSet('clientFilter', 'with_siblings');

        $this->assertSame('with_siblings', $this->savedView()['clientFilter']);

        Livewire::test(StudentsGrid::class)
            ->call('rememberClientFilter', 'nonsense')
            ->assertSet('clientFilter', 'all');
    }

    public function test_focus_mode_has_the_add_student_button_for_staff_only(): void
    {
        Livewire::test(StudentsGrid::class, ['focus' => true])
            ->assertSee(__('grid.exit_focus'))
            ->assertSee(__('student.add'));

        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(StudentsGrid::class, ['focus' => true])
            ->assertSee(__('grid.exit_focus'))
            ->assertDontSee(__('student.add'));
    }

    public function test_a_new_student_opens_the_page_that_holds_it_and_is_scrolled_to(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            Student::create(['name' => "Student {$i}"]);
        }

        $grid = Livewire::test(StudentsGrid::class, ['focus' => true]);
        $new = Student::create(['name' => 'Just Added']);

        $grid->dispatch('student-created', studentId: $new->id)
            ->assertSet('paginators.page', 2)
            ->assertSet('focus', true)
            ->assertDispatched('grid-show-row', id: $new->id)
            ->assertSee('Just Added');
    }

    public function test_a_new_student_hidden_by_the_state_filter_does_not_move_the_page(): void
    {
        $new = Student::create(['name' => 'Hidden Kid', 'is_hidden' => true]);

        Livewire::test(StudentsGrid::class)
            ->set('filterStatus', 'visible')
            ->dispatch('student-created', studentId: $new->id)
            ->assertNotDispatched('grid-show-row');
    }
}
