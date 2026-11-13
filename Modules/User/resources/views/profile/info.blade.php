<x-layouts.app title="Profile - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Profile</h1>
            <p class="mt-1 text-sm text-gray-500">This information may be visible to your contacts.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    {{-- profile photo and cover photo --}}
    <section class="mb-6 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div style="height:120px;background:{{ $user->profile?->cover_photo ? 'url(' . e($user->profile->cover_photo) . ') center/cover' : 'linear-gradient(135deg,#e0e7ff,#fae8ff)' }}" role="img" aria-label="Cover photo"></div>
        <div class="p-6" style="margin-top:-56px">
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
                @if ($user->profile_photo)
                    <img src="{{ $user->profile_photo }}" alt="Your profile photo" style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:4px solid #fff;background:#fff">
                @else
                    <span style="width:96px;height:96px;border-radius:50%;border:4px solid #fff;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:600" class="bg-brand-100 text-brand-700">{{ strtoupper(substr($user->full_name ?? '?', 0, 1)) }}</span>
                @endif
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach (['avatar' => 'Profile photo', 'cover' => 'Cover photo'] as $kind => $label)
                        <form method="POST" action="{{ route('profile.photo.update', $kind) }}" enctype="multipart/form-data" style="display:inline">
                            @csrf
                            <label class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                {{ ($kind === 'avatar' ? $user->profile_photo : $user->profile?->cover_photo) ? 'Change' : 'Add' }} {{ strtolower($label) }}
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="sr-only" onchange="this.form.submit()">
                            </label>
                        </form>
                        @if ($kind === 'avatar' ? $user->profile_photo : $user->profile?->cover_photo)
                            <form method="POST" action="{{ route('profile.photo.delete', $kind) }}" style="display:inline">@csrf @method('DELETE')
                                <button class="rounded-lg px-3 py-2 text-sm text-gray-500 hover:bg-gray-100">Remove {{ strtolower($label) }}</button></form>
                        @endif
                    @endforeach
                </div>
            </div>
            @error('photo')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="mt-2 text-xs text-gray-400">JPG, PNG or WebP. Profile photo up to 4 MB, cover photo up to 6 MB.</p>
        </div>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.update') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')

            <div>
                <label class="block text-sm font-medium text-gray-700">First name</label>
                <input type="text" name="first_name" value="{{ old('first_name', $user->profile?->first_name) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Last name</label>
                <input type="text" name="last_name" value="{{ old('last_name', $user->profile?->last_name) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Display name</label>
                <input type="text" name="display_name" value="{{ old('display_name', $user->profile?->display_name) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                <p class="mt-1 text-xs text-gray-400">Shown instead of your name if set.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Date of birth</label>
                <input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($user->profile?->date_of_birth)->format('Y-m-d')) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Gender</label>
                <select name="gender" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    <option value="">Prefer not to say</option>
                    @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other', 'prefer_not_to_say' => 'Prefer not to say'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('gender', $user->profile?->gender) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Website</label>
                <input type="url" name="website" value="{{ old('website', $user->profile?->website) }}"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Bio</label>
                <textarea name="bio" rows="3"
                          class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">{{ old('bio', $user->profile?->bio) }}</textarea>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Save profile
                </button>
            </div>
        </form>
    </section>
</x-layouts.app>
