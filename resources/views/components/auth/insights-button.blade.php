{{-- Quick sign up / log in with a Meet Insights account: Insights signs the person in (or lets them sign up free),
     then its "Open Meet" step creates or links the Meet account and comes back here (/sso/insights). --}}
@if (config('services.insights.sso_secret'))
    <a href="{{ rtrim(config('services.insights.url'), '/') }}/go/meet"
       class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
        <img src="{{ asset('images/meet-icon.png') }}" alt="" class="h-4 w-4">
        {{ $label ?? 'Continue with Meet Insights' }}
    </a>
@endif
