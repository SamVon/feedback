@if (filled($service['icon_url'] ?? null))
    <img class="eg-cs-icon" src="{{ $service['icon_url'] }}" alt="" />
@elseif (($service['icon'] ?? 'star') === 'headset')
    <svg class="eg-cs-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
        <path d="M4 13v-2a8 8 0 0 1 16 0v2M20 17v2a3 3 0 0 1-3 3h-3" />
        <rect x="2" y="11" width="4" height="7" rx="2" /><rect x="18" y="11" width="4" height="7" rx="2" />
        <path d="M9 11h6M9 14h6" />
    </svg>
@else
    <x-filament::icon :icon="($service['icon'] ?? 'star') === 'chat' ? 'heroicon-o-chat-bubble-left-right' : 'heroicon-s-star'" class="eg-cs-icon" />
@endif
