<x-layouts.app title="Chat defaults - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Chat defaults</h1>
            <p class="mt-1 text-sm text-gray-500">Applied to new conversations - existing chats keep whatever they already have.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.preferences') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-medium text-gray-700">Default wallpaper</label>
                <select name="default_wallpaper_id" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    <option value="">None</option>
                    @foreach ($wallpapers as $wallpaper)
                        <option value="{{ $wallpaper->id }}" @selected((string) old('default_wallpaper_id', $user->preferences?->default_wallpaper_id) === (string) $wallpaper->id)>{{ $wallpaper->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Font size</label>
                <select name="font_size" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    @foreach (['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('font_size', $user->preferences?->font_size) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Save chat defaults
                </button>
            </div>
        </form>
    </section>
</x-layouts.app>
