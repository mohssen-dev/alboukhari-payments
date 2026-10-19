{{-- Which way a campaign goes out: BulkGate, or a paired phone's SIM / WhatsApp (with the phone's name and whether it is online). Nothing while the sender phone is hidden (config/features.php). --}}
@if (!config('features.sender_phone'))
@elseif ($campaign->isDeviceChannel())
    @php $device = $campaign->senderDevice; @endphp
    <span class="pill {{ $device?->isOnline() ? 'pill-success' : 'pill-warning' }} campaign-channel" title="{{ $device ? ($device->isOnline() ? __('sender.online') : __('sender.last_seen') . ' ' . ($device->last_seen_at?->diffForHumans() ?? '—')) : '' }}">
        {{ $campaign->deviceChannelName() === 'whatsapp' ? '💬 WhatsApp' : '📱 SMS' }} · {{ $device?->name ?? '—' }}
    </span>
@else
    <span class="pill pill-muted campaign-channel">📲 BulkGate</span>
@endif
