<div>
    <div class="toolbar">
        <div style="position:relative;flex:1;max-width:360px">
            <svg style="position:absolute;top:50%;inset-inline-start:10px;transform:translateY(-50%);color:var(--color-text-soft)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                   class="form-control" placeholder="{{ __('users.search_placeholder') }}"
                   style="width:100%;padding-inline-start:30px;height:38px">
        </div>
        <button type="button" class="btn btn-primary" wire:click="openCreate">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            {{ __('users.add_user') }}
        </button>
    </div>

    @if ($showForm)
        <form wire:submit.prevent="save" class="card mt-3">
            <h3>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    @if($editingId)
                        <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/>
                    @else
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    @endif
                </svg>
                {{ $editingId ? __('users.edit_user') : __('users.add_user') }}
            </h3>
            <div class="card-body">
                <div class="grid-2">
                    <label>
                        <span class="lbl">{{ __('users.name') }}</span>
                        <input type="text" wire:model="name" class="form-control" autocomplete="off">
                        @error('name')<small class="text-danger">{{ $message }}</small>@enderror
                    </label>
                    <label>
                        <span class="lbl">{{ __('users.email') }}</span>
                        <input type="email" wire:model="email" class="form-control" autocomplete="off">
                        @error('email')<small class="text-danger">{{ $message }}</small>@enderror
                    </label>
                    <label>
                        <span class="lbl">{{ __('users.role') }}</span>
                        <select wire:model="role" class="form-control">
                            <option value="admin">{{ __('users.role_admin') }}</option>
                            <option value="staff">{{ __('users.role_staff') }}</option>
                            <option value="viewer">{{ __('users.role_viewer') }}</option>
                        </select>
                        @error('role')<small class="text-danger">{{ $message }}</small>@enderror
                    </label>
                    <label class="check-row">
                        <input type="checkbox" wire:model="is_active">
                        <span>{{ __('users.active') }}</span>
                        @error('is_active')<small class="text-danger">{{ $message }}</small>@enderror
                    </label>
                    <label>
                        <span class="lbl">{{ __('users.password') }} @if($editingId) <small style="color:var(--color-text-soft);font-weight:400;text-transform:none">({{ __('users.password_leave_blank') }})</small> @endif</span>
                        <input type="password" wire:model="password" class="form-control" autocomplete="new-password">
                        @error('password')<small class="text-danger">{{ $message }}</small>@enderror
                    </label>
                    <label>
                        <span class="lbl">{{ __('users.password_confirm') }}</span>
                        <input type="password" wire:model="password_confirmation" class="form-control" autocomplete="new-password">
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        {{ __('common.save') }}
                    </button>
                    <button type="button" class="btn" wire:click="cancel">{{ __('common.cancel') }}</button>
                </div>
            </div>
        </form>
    @endif

    <div class="table-wrap mt-3">
        <table class="data-table">
            <thead>
                <tr>
                    <th>{{ __('users.name') }}</th>
                    <th>{{ __('users.email') }}</th>
                    <th>{{ __('users.role') }}</th>
                    <th>{{ __('users.status') }}</th>
                    <th>{{ __('users.last_login') }}</th>
                    <th style="text-align:end">{{ __('columns.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr>
                        <td>
                            <div style="display:inline-flex;align-items:center;gap:10px">
                                <span class="user-avatar" style="width:30px;height:30px;font-size:12px">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</span>
                                <strong>{{ $u->name }}</strong>
                            </div>
                        </td>
                        <td class="muted">{{ $u->email }}</td>
                        <td><span class="badge role-{{ $u->role }}">{{ __('users.role_'.$u->role) }}</span></td>
                        <td>
                            @if($u->is_active)
                                <span class="badge ok"><span class="dot"></span>{{ __('users.active') }}</span>
                            @else
                                <span class="badge muted"><span class="dot"></span>{{ __('users.inactive') }}</span>
                            @endif
                        </td>
                        <td class="muted">{{ $u->last_login_at?->diffForHumans() ?? '—' }}</td>
                        <td style="text-align:end;white-space:nowrap">
                            <button class="btn btn-xs" wire:click="openEdit({{ $u->id }})">{{ __('common.edit') }}</button>
                            <button class="btn btn-xs"
                                    wire:click="toggleActive({{ $u->id }})"
                                    wire:confirm="{{ $u->is_active ? __('users.confirm_disable') : __('users.confirm_enable') }}">
                                {{ $u->is_active ? __('users.disable') : __('users.enable') }}
                            </button>
                            <button class="btn btn-xs btn-danger"
                                    wire:click="delete({{ $u->id }})"
                                    wire:confirm="{{ __('users.confirm_delete') }}">
                                {{ __('common.delete') }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <div class="empty">
                            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/></svg>
                            {{ __('common.no_results') }}
                        </div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2">{{ $users->links() }}</div>
</div>
