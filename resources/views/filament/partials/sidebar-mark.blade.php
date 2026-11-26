<a class="eg-sidebar-mark" href="{{ \App\Filament\Pages\FeedbackCenter::getUrl() }}" x-cloak x-show="! $store.sidebar.isOpen">
    <img src="{{ asset('images/easy-gold-mark.svg') }}" alt="Easy Gold" width="40" height="40">
</a>
<x-filament::icon-button
    class="eg-sidebar-mobile-close"
    icon="heroicon-o-x-mark"
    color="gray"
    :label="__('filament-panels::layout.actions.sidebar.close.label')"
    aria-controls="fi-main-sidebar"
    x-cloak
    x-show="$store.sidebar.isOpenMobile"
    x-on:click="$store.sidebar.close()"
/>
