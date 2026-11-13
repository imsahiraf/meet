@php $adminCurrent = request()->route()?->getName(); @endphp
<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Admin Panel</h1>
    {{-- Matches the app's own established pill-tab-strip convention
         (Chat's All/Chats/Groups, Calendar's Month/Week/Day) instead of
         a bare underline row - the underline style read as noticeably
         plainer/less polished than the rest of the app on mobile, with
         no visual affordance hinting there were more tabs to scroll to. --}}
    <nav class="mt-4 flex gap-1 overflow-x-auto rounded-xl border border-gray-100 bg-white p-1 shadow-sm" role="tablist">
        @foreach ([
            ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
            ['route' => 'admin.users', 'label' => 'Users'],
            ['route' => 'admin.roles', 'label' => 'Roles & Permissions'],
            ['route' => 'admin.settings', 'label' => 'Settings'],
            ['route' => 'admin.logs', 'label' => 'Audit Logs'],
            ['route' => 'admin.archives', 'label' => 'Archives'],
            ['route' => 'admin.environment', 'label' => 'Environment'],
        ] as $item)
            <a href="{{ route($item['route']) }}"
               class="whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium
                      {{ $adminCurrent === $item['route'] ? 'bg-brand-50 text-brand-700' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700' }}">
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>
</div>
