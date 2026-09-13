<?php

namespace Tests\Feature;

use App\Livewire\StudentForm;
use App\Models\Family;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Add / edit a student from the app (grid ➕, row menu, family window, side panel). */
class StudentFormTest extends TestCase
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
            'email' => $role . '@student-form.test',
            'password' => Hash::make('secret123'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_a_new_student_gets_the_next_number_and_this_month_as_enrolment(): void
    {
        Student::create(['name' => 'Existing', 'external_id' => 41]);

        Livewire::test(StudentForm::class)
            ->call('open')
            ->assertSet('isOpen', true)
            ->assertSet('external_id', '42')
            ->assertSet('enrolled_at', now()->startOfMonth()->format('Y-m-d'));
    }

    public function test_adding_a_student_with_a_known_number_joins_that_family(): void
    {
        $f = Family::create(['guardian_name' => 'Test Family', 'phone_primary_e164' => '+31612345678']);
        Student::create(['name' => 'Sibling', 'family_id' => $f->id, 'phone_primary_e164' => '+31612345678', 'external_id' => 1]);

        Livewire::test(StudentForm::class)
            ->call('open')
            ->set('name', 'New Kid')
            ->set('phone_primary_raw', '0612345678')
            ->assertSee('Test Family')              // the preview says which family
            ->set('default_fee_amount', '25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('student-created')
            ->assertSet('isOpen', false);

        $new = Student::where('name', 'New Kid')->firstOrFail();
        $this->assertSame($f->id, $new->family_id);
        $this->assertSame('+31612345678', $new->phone_primary_e164);
        $this->assertEquals(25, (float) $new->default_fee_amount);
        $this->assertSame(2, (int) $new->external_id);
        $this->assertTrue((bool) $new->allow_sms);
    }

    public function test_a_new_number_starts_a_new_family(): void
    {
        Livewire::test(StudentForm::class)
            ->call('open')
            ->set('name', 'Only Child')
            ->set('phone_primary_raw', '0687654321')
            ->call('save')
            ->assertHasNoErrors();

        $s = Student::where('name', 'Only Child')->firstOrFail();
        $this->assertNotNull($s->family_id);
        $this->assertSame('+31687654321', $s->family->phone_primary_e164);
    }

    public function test_a_family_window_can_prefill_the_familys_number(): void
    {
        Livewire::test(StudentForm::class)
            ->call('open', null, '+31612345678')
            ->assertSet('phone_primary_raw', '+31612345678');
    }

    public function test_duplicate_student_number_is_rejected(): void
    {
        Student::create(['name' => 'Seven', 'external_id' => 7]);

        Livewire::test(StudentForm::class)
            ->call('open')
            ->set('name', 'Copy')
            ->set('external_id', '7')
            ->call('save')
            ->assertHasErrors('external_id');

        $this->assertSame(1, Student::count());
    }

    public function test_invalid_phone_is_rejected(): void
    {
        Livewire::test(StudentForm::class)
            ->call('open')
            ->set('name', 'Bad Phone')
            ->set('phone_primary_raw', '12')
            ->call('save')
            ->assertHasErrors('phone_primary_raw');

        $this->assertSame(0, Student::where('name', 'Bad Phone')->count());
    }

    public function test_withdrawal_before_enrolment_is_rejected(): void
    {
        Livewire::test(StudentForm::class)
            ->call('open')
            ->set('name', 'Dates')
            ->set('enrolled_at', '2026-05-01')
            ->set('withdrawn_at', '2026-04-01')
            ->call('save')
            ->assertHasErrors('withdrawn_at');
    }

    public function test_editing_saves_the_student_and_patches_its_grid_row(): void
    {
        $s = Student::create(['name' => 'Old Name', 'external_id' => 5, 'default_fee_amount' => 30]);

        Livewire::test(StudentForm::class)
            ->call('open', $s->id)
            ->assertSet('name', 'Old Name')
            ->assertSet('external_id', '5')
            ->set('name', 'New Name')
            ->set('default_fee_amount', '20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('grid-row-updated')
            ->assertDispatched('student-updated');

        $this->assertSame('New Name', $s->fresh()->name);
        $this->assertEquals(20, (float) $s->fresh()->default_fee_amount);
    }

    public function test_editing_without_touching_the_phone_keeps_the_family(): void
    {
        $f1 = Family::create(['phone_primary_e164' => '+31622222222']);
        $s = Student::create(['name' => 'Linked', 'family_id' => $f1->id, 'phone_primary_e164' => '+31611111111']);

        Livewire::test(StudentForm::class)
            ->call('open', $s->id)
            ->set('name', 'Linked Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($f1->id, $s->fresh()->family_id, 'saving another field must not re-link the family');
    }

    public function test_changing_the_phone_moves_the_student_to_that_family(): void
    {
        $f1 = Family::create(['phone_primary_e164' => '+31611111111']);
        $f2 = Family::create(['phone_primary_e164' => '+31633333333']);
        $s = Student::create(['name' => 'Mover', 'family_id' => $f1->id, 'phone_primary_e164' => '+31611111111']);

        Livewire::test(StudentForm::class)
            ->call('open', $s->id)
            ->set('phone_primary_raw', '0633333333')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($f2->id, $s->fresh()->family_id);
    }

    public function test_viewer_cannot_add_or_edit_students(): void
    {
        $s = Student::create(['name' => 'Protected']);
        $this->actingAs($this->user(User::ROLE_VIEWER));

        Livewire::test(StudentForm::class)->call('open')->set('name', 'Sneaky')->call('save');
        Livewire::test(StudentForm::class)->call('open', $s->id)->set('name', 'Renamed')->call('save');

        $this->assertSame(0, Student::where('name', 'Sneaky')->count(), 'SECURITY: a viewer added a student');
        $this->assertSame('Protected', $s->fresh()->name, 'SECURITY: a viewer edited a student');
    }
}
