<x-filament-panels::page>
    <section class="eg-menu-panel" aria-labelledby="feedback-center-heading">
        <h1 id="feedback-center-heading">{{ __('feedback.feedback_center') }}</h1>
        <div class="eg-menu-grid">
            @forelse ($this->menuCards() as $card)
                <a class="eg-menu-card" href="{{ $card['url'] }}">
                    <x-filament::icon :icon="$card['icon']" class="eg-menu-icon" />
                    <span>{{ $card['label'] }}</span>
                </a>
            @empty
                <p class="eg-menu-empty">{{ __('feedback.no_menu_access') }}</p>
            @endforelse
        </div>
    </section>
</x-filament-panels::page>
