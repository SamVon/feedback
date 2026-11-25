<nav class="eg-dashboard-tabs" aria-label="{{ __('feedback.feedback_center') }}">
    @if (\App\Filament\Pages\Dashboard::canAccess())
        <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}" @if ($active === 'dashboard') aria-current="page" @endif>{{ __('feedback.dashboard') }}</a>
    @endif
    @if (\App\Filament\Pages\Customers::canAccess())
        <a href="{{ \App\Filament\Pages\Customers::getUrl() }}" @if ($active === 'customers') aria-current="page" @endif>{{ __('feedback.customer_rating_history') }}</a>
    @endif
    @if (\App\Filament\Resources\FeedbackResource::canViewAny())
        <a href="{{ \App\Filament\Resources\FeedbackResource::getUrl() }}" @if ($active === 'feedback') aria-current="page" @endif>{{ __('feedback.feedback_history') }}</a>
    @endif
</nav>
