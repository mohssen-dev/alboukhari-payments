<?php

namespace App\Livewire;

use App\Models\Family;
use App\Models\Student;
use App\Services\FeeResolver;
use App\Support\AuthorizesLivewireWrite;
use App\Support\DispatchesGridRow;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Add a student, or edit one — a single modal mounted in the layout and
 * opened from the grid (➕ and the row menu), the family window and the side
 * panel.
 *
 * Family membership follows the importer's rule: children who share a
 * primary phone number are one family. A new student joins (or starts) the
 * family of their number; editing re-links only when the number changes, so
 * a family is never broken up by saving an unrelated field.
 */
class StudentForm extends Component
{
    use AuthorizesLivewireWrite;
    use DispatchesGridRow;

    public bool $isOpen = false;
    #[Locked] public ?int $studentId = null;

    public string $external_id = '';
    public string $name = '';
    public string $phone_primary_raw = '';
    public string $phone_secondary_raw = '';
    public string $default_fee_amount = '';
    public string $enrolled_at = '';
    public string $withdrawn_at = '';
    public string $notes = '';
    public bool $allow_sms = true;

    #[On('open-student-form')]
    public function open(?int $studentId = null, ?string $phone = null): void
    {
        $this->resetForm();

        if ($studentId) {
            $s = Student::find($studentId);
            if (!$s) {
                $this->dispatch('toast', message: __('flash.deleted'), type: 'error');
                return;
            }
            $this->studentId = $s->id;
            $this->external_id = $s->external_id !== null ? (string) $s->external_id : '';
            $this->name = $s->name;
            $this->phone_primary_raw = (string) ($s->phone_primary_raw ?? $s->phone_primary_e164 ?? '');
            $this->phone_secondary_raw = (string) ($s->phone_secondary_raw ?? $s->phone_secondary_e164 ?? '');
            $this->default_fee_amount = $s->default_fee_amount !== null ? (string) $s->default_fee_amount : '';
            $this->enrolled_at = $s->enrolled_at?->format('Y-m-d') ?? '';
            $this->withdrawn_at = $s->withdrawn_at?->format('Y-m-d') ?? '';
            $this->notes = (string) ($s->notes ?? '');
            $this->allow_sms = (bool) $s->allow_sms;
        } else {
            // A new student: the next free sheet number, joining this month.
            // withTrashed — the unique index still holds soft-deleted numbers.
            $this->external_id = (string) (((int) Student::withTrashed()->max('external_id')) + 1);
            $this->enrolled_at = now()->startOfMonth()->format('Y-m-d');
            $this->phone_primary_raw = (string) ($phone ?? '');
        }

        $this->isOpen = true;
    }

    public function save(): void
    {
        $this->assertCanWrite();

        $this->validate([
            'name' => 'required|string|max:255',
            'external_id' => ['nullable', 'integer', 'min:1', Rule::unique('students', 'external_id')->ignore($this->studentId)],
            'phone_primary_raw' => 'nullable|string|max:40',
            'phone_secondary_raw' => 'nullable|string|max:40',
            'default_fee_amount' => 'nullable|numeric|min:0|max:10000',
            'enrolled_at' => 'nullable|date',
            // Only compare with enrolled_at when there is one — "" is not a date.
            'withdrawn_at' => 'nullable|date' . ($this->enrolled_at !== '' ? '|after_or_equal:enrolled_at' : ''),
            'notes' => 'nullable|string|max:2000',
            'allow_sms' => 'boolean',
        ], [], [
            'name' => __('columns.name'),
            'external_id' => __('student.external_id'),
            'default_fee_amount' => __('Default monthly fee'),
            'enrolled_at' => __('panel.enrolled_at'),
            'withdrawn_at' => __('panel.withdrawn_at'),
        ]);

        $primary = $this->validPhone('phone_primary_raw');
        $secondary = $this->validPhone('phone_secondary_raw');
        if ($primary === false || $secondary === false) {
            return;
        }

        $isNew = $this->studentId === null;

        $student = DB::transaction(function () use ($isNew, $primary, $secondary) {
            $student = $isNew ? new Student() : Student::findOrFail($this->studentId);

            $phoneChanged = $isNew || $student->phone_primary_e164 !== $primary;

            $student->external_id = $this->external_id !== '' ? (int) $this->external_id : null;
            $student->name = trim($this->name);
            $student->phone_primary_raw = trim($this->phone_primary_raw) ?: null;
            $student->phone_primary_e164 = $primary;
            $student->phone_secondary_raw = trim($this->phone_secondary_raw) ?: null;
            $student->phone_secondary_e164 = $secondary;
            $student->default_fee_amount = $this->default_fee_amount !== '' ? round((float) $this->default_fee_amount, 2) : null;
            $student->enrolled_at = $this->enrolled_at !== '' ? $this->enrolled_at : null;
            $student->withdrawn_at = $this->withdrawn_at !== '' ? $this->withdrawn_at : null;
            $student->notes = trim($this->notes) !== '' ? trim($this->notes) : null;
            $student->allow_sms = $this->allow_sms;

            if ($phoneChanged) {
                $student->family_id = $primary
                    ? Family::firstOrCreate(['phone_primary_e164' => $primary], ['preferred_language' => 'nl'])->id
                    : null;
            }

            $student->save();

            return $student;
        });

        if ($isNew) {
            $this->dispatch('student-created', studentId: $student->id);
            $this->dispatch('toast', type: 'success', message: __('student.created', ['name' => $student->name]));
        } else {
            $this->dispatchGridRow($student->id);
            $this->dispatch('student-updated', studentId: $student->id);
            $this->dispatch('toast', type: 'success', message: __('student.updated', ['name' => $student->name]));
        }

        $this->close();
    }

    public function close(): void
    {
        $this->resetForm();
        $this->isOpen = false;
    }

    public function render()
    {
        [$familyHint, $familyTone] = $this->isOpen ? $this->familyPreview() : [null, 'muted'];

        return view('livewire.student-form', [
            'familyHint' => $familyHint,
            'familyTone' => $familyTone,
            'defaultFee' => FeeResolver::defaultMonthlyFee(),
        ]);
    }

    /** Which family the number on screen would put this student in. */
    private function familyPreview(): array
    {
        $raw = trim($this->phone_primary_raw);
        if ($raw === '') {
            return [__('student.family_none'), 'muted'];
        }

        $e164 = PhoneNormalizer::normalize($raw);
        if (!PhoneNormalizer::isValid($e164)) {
            return [__('student.phone_invalid'), 'danger'];
        }

        $family = Family::withCount('students')->where('phone_primary_e164', $e164)->first();
        if (!$family) {
            return [__('student.family_new'), 'info'];
        }

        return [__('student.family_join', ['name' => $family->displayName(), 'count' => $family->students_count]), 'success'];
    }

    /** E.164 for a filled-in field, null for an empty one, false (with an error) for garbage. */
    private function validPhone(string $field): string|null|false
    {
        $raw = trim($this->{$field});
        if ($raw === '') {
            return null;
        }

        $e164 = PhoneNormalizer::normalize($raw);
        if (!PhoneNormalizer::isValid($e164)) {
            $this->addError($field, __('student.phone_invalid'));
            return false;
        }

        return $e164;
    }

    private function resetForm(): void
    {
        $this->reset(['studentId', 'external_id', 'name', 'phone_primary_raw', 'phone_secondary_raw', 'default_fee_amount', 'enrolled_at', 'withdrawn_at', 'notes', 'allow_sms']);
        $this->resetValidation();
    }
}
