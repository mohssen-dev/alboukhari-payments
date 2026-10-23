<?php

namespace App\Livewire;

use App\Models\Family;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Support\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * The logs page: every change made in the system (payments, students,
 * deletions, bank sync, settings, users) and every message sent — each
 * searchable by student and filterable, and a click away from the student.
 *
 * Filters live in the URL so a filtered view can be reloaded or shared.
 */
class LogsPage extends Component
{
    use WithPagination;

    public const PER_PAGE = 40;

    /** The kinds of change, as log names (sender_device only while that feature is on). */
    public const KINDS = ['payment', 'student', 'deletion', 'bank-sync', 'campaign', 'user', 'settings', 'sender_device'];

    #[Url(as: 'tab')]
    public string $tab = 'activity';

    /** Name, student number or phone. */
    #[Url(as: 'q')]
    public string $search = '';

    /** One student's history (set by the 🔎 next to a name). */
    #[Url(as: 'student')]
    public ?int $studentId = null;

    #[Url(as: 'kind')]
    public string $kind = '';

    #[Url(as: 'action')]
    public string $event = '';

    #[Url(as: 'user')]
    public string $causer = '';

    #[Url(as: 'status')]
    public string $status = '';

    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function updating($property): void
    {
        if (in_array($property, ['tab', 'search', 'studentId', 'kind', 'event', 'causer', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function updatedTab(): void
    {
        if (!in_array($this->tab, ['activity', 'messages'], true)) {
            $this->tab = 'activity';
        }
    }

    public function forStudent(int $id): void
    {
        $this->studentId = $id;
        $this->search = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'studentId', 'kind', 'event', 'causer', 'status', 'from', 'to']);
        $this->resetPage();
    }

    /** Students matching the search box — null when the box is empty. @return list<int>|null */
    private function searchedStudentIds(): ?array
    {
        $s = trim($this->search);
        if ($s === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $s);

        return Student::withTrashed()
            ->where(function ($q) use ($s, $digits) {
                $q->where('name', 'like', "%{$s}%");
                if ($digits !== '') {
                    $q->orWhere('external_id', $digits)->orWhere('id', $digits)
                      ->orWhere('phone_primary_e164', 'like', '%' . ltrim($digits, '0') . '%');
                }
            })
            ->limit(300)
            ->pluck('id')
            ->all();
    }

    private function activityQuery(): Builder
    {
        $q = Activity::query()->with('causer')->latest('id');

        if (!config('features.sender_phone')) {
            $q->where('log_name', '!=', 'sender_device');
        }
        if ($this->kind !== '' && in_array($this->kind, self::KINDS, true)) {
            $q->where('log_name', $this->kind);
        }
        if (in_array($this->event, ['created', 'updated', 'deleted'], true)) {
            // Deletions logged by hand ("student deleted, payments kept") count as deleted.
            $this->event === 'deleted'
                ? $q->where(fn ($w) => $w->where('event', 'deleted')->orWhere('log_name', 'deletion'))
                : $q->where('event', $this->event);
        }
        if ($this->causer !== '') {
            $q->where('causer_type', User::class)->where('causer_id', (int) $this->causer);
        }
        $this->applyDates($q);

        $ids = $this->studentId ? [$this->studentId] : $this->searchedStudentIds();
        if ($ids !== null) {
            // A student's own changes, and the payments made for them (their
            // student_id lives in the logged attributes, not in a column).
            $q->where(function ($w) use ($ids) {
                $w->where(fn ($s) => $s->where('subject_type', Student::class)->whereIn('subject_id', $ids ?: [0]))
                  ->orWhere(fn ($p) => $p->where('log_name', 'payment')->whereIn('attribute_changes->attributes->student_id', $ids ?: [0]))
                  ->orWhere(fn ($p) => $p->where('log_name', 'payment')->whereIn('attribute_changes->old->student_id', $ids ?: [0]));
            });
        }

        return $q;
    }

    private function messageQuery(): Builder
    {
        $q = MessageLog::query()->latest('id');

        if (!config('features.sender_phone')) {
            $q->whereNotIn('provider', ['device_sms', 'device_whatsapp']);
        }
        if ($this->status === 'ok') {
            $q->where('status', 'not like', 'ERROR%');
        } elseif ($this->status === 'failed') {
            $q->where('status', 'like', 'ERROR%');
        }
        $this->applyDates($q);

        if ($this->studentId) {
            $q->where('student_id', $this->studentId);
        } elseif (($s = trim($this->search)) !== '') {
            $ids = $this->searchedStudentIds() ?: [0];
            $digits = ltrim(preg_replace('/\D+/', '', $s), '0');
            $q->where(function ($w) use ($ids, $digits) {
                $w->whereIn('student_id', $ids);
                if (strlen($digits) >= 4) {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        return $q;
    }

    private function applyDates(Builder $q): void
    {
        if ($this->from !== '' && strtotime($this->from)) {
            $q->where('created_at', '>=', $this->from . ' 00:00:00');
        }
        if ($this->to !== '' && strtotime($this->to)) {
            $q->where('created_at', '<=', $this->to . ' 23:59:59');
        }
    }

    public function render()
    {
        $isMessages = $this->tab === 'messages';
        $page = $isMessages ? $this->messageQuery()->paginate(self::PER_PAGE) : $this->activityQuery()->paginate(self::PER_PAGE);

        // Students and families named on this page, loaded once.
        $studentIds = $isMessages
            ? $page->getCollection()->pluck('student_id')->filter()->unique()
            : $page->getCollection()->map(fn (Activity $a) => ActivityPresenter::studentIdOf($a))->filter()->unique();
        if ($this->studentId) {
            $studentIds->push($this->studentId);
        }
        $students = Student::withTrashed()->with('family')->whereIn('id', $studentIds->unique()->values())->get()->keyBy('id');

        $rows = $isMessages ? collect() : $this->presentActivities($page->getCollection(), $students);

        return view('livewire.logs-page', [
            'page' => $page,
            'rows' => $rows,
            'students' => $students,
            'filterStudent' => $this->studentId ? $students->get($this->studentId) : null,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'kinds' => array_values(array_filter(self::KINDS, fn ($k) => $k !== 'sender_device' || config('features.sender_phone'))),
            'hasFilters' => $this->search !== '' || $this->studentId || $this->kind !== '' || $this->event !== '' || $this->causer !== '' || $this->status !== '' || $this->from !== '' || $this->to !== '',
        ]);
    }

    /** @param Collection<int, Activity> $activities */
    private function presentActivities(Collection $activities, Collection $students): Collection
    {
        $familyIds = $activities->flatMap(function (Activity $a) {
            $c = ActivityPresenter::changes($a);

            return [$c['attributes']['family_id'] ?? null, $c['old']['family_id'] ?? null];
        })->filter()->unique();
        $families = Family::whereIn('id', $familyIds)->get()->keyBy('id');
        $presenter = new ActivityPresenter($students, $families);

        return $activities->map(fn (Activity $a) => ['activity' => $a] + $presenter->present($a));
    }
}
