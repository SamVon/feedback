<div class="eg-account-bar">
    @include('filament.partials.locale')
    <span class="eg-account-name">
        <x-filament::icon icon="heroicon-s-user-circle" />
        <span>{{ filament()->getUserName(filament()->auth()->user()) }}</span>
    </span>
    <form action="{{ filament()->getLogoutUrl() }}" method="post">
        @csrf
        <button type="submit" class="eg-logout">
            <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" />
            <span>{{ __('feedback.sign_out') }}</span>
        </button>
    </form>
</div>
