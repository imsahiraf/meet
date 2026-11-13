@php
    // festive offer set in Meet Insights (Control center > Offers); cached 10 minutes, nothing shown when Insights cannot be reached
    $offer = \Illuminate\Support\Facades\Cache::remember('insights-offer-meet', 600, function () {
        try {
            return \Illuminate\Support\Facades\Http::timeout(3)->acceptJson()->get(rtrim((string) config('services.insights.url'), '/').'/api/public/offer', ['product' => 'meet'])->json('offer');
        } catch (\Throwable) {
            return null;
        }
    });
@endphp
@if (is_array($offer) && ! empty($offer['title']))
    <div role="region" aria-label="Offer" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:center;padding:8px 16px;color:#fff;font-size:14px;background:linear-gradient(90deg,{{ e($offer['colors'][0] ?? '#4f46e5') }},{{ e($offer['colors'][1] ?? '#7c3aed') }})">
        <span>{{ $offer['emoji'] ?? '' }} <strong>{{ $offer['title'] }}</strong>@if (! empty($offer['text'])) - {{ $offer['text'] }}@endif @if (! empty($offer['code'])) Code <b>{{ $offer['code'] }}</b>@endif</span>
        @if (! empty($offer['cta_url']) && str_starts_with($offer['cta_url'], 'https://'))<a href="{{ $offer['cta_url'] }}" style="color:#fff;font-weight:700">{{ $offer['cta_label'] ?: 'See offer' }}</a>@endif
        @if (! empty($offer['offline_text']))<small style="flex-basis:100%;text-align:center">{{ $offer['offline_text'] }}@if (! empty($offer['offline_url']) && str_starts_with($offer['offline_url'], 'https://')) <a href="{{ $offer['offline_url'] }}" style="color:#fff;font-weight:700">Ask us</a>@endif</small>@endif
    </div>
    @if (($offer['theme'] ?? 'none') !== 'none')
        {{-- the festival's look while its offer runs: a colour strip on top of every page --}}
        <div aria-hidden="true" style="position:fixed;inset:0 0 auto 0;height:4px;z-index:90;pointer-events:none;background:linear-gradient(90deg,{{ e($offer['colors'][0] ?? '#4f46e5') }},{{ e($offer['colors'][1] ?? '#7c3aed') }},{{ e($offer['colors'][0] ?? '#4f46e5') }})"></div>
    @endif
@endif
