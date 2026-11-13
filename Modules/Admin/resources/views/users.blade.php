<x-layouts.app title="User management - Meet">
    @include('admin::partials.nav')

    <div class="mb-4 flex gap-1 rounded-xl border border-gray-100 bg-white p-1 shadow-sm">
        <button type="button" data-account-type="" class="account-type-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">All users</button>
        <button type="button" data-account-type="real" class="account-type-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Real users</button>
        <button type="button" data-account-type="fake" class="account-type-tab flex-1 rounded-lg px-3 py-1.5 text-sm font-medium">Load-test users</button>
    </div>

    <div class="mb-4 flex gap-2">
        <input type="text" id="user-search" placeholder="Search by name, email, or phone&hellip;" class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        <select id="user-status-filter" class="w-40 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
            <option value="pending">Pending</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        {{-- overflow-x-auto (not overflow-hidden) on this inner wrapper -
             the outer div's overflow-hidden alone was clipping Status/
             Roles/Actions off-screen on mobile with no way to reach
             them, since nothing here scrolled horizontally. --}}
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Email</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Roles</th>
                        <th class="px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody id="users-table-body" class="divide-y divide-gray-100"></tbody>
            </table>
            <p id="users-empty" class="hidden px-4 py-10 text-center text-sm text-gray-400">No users found.</p>
        </div>
        <div id="reset-password-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-gray-900/40 px-4">
            <div class="w-full max-w-sm rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Reset password</h2>
                <p class="mt-1 text-sm text-gray-500" id="reset-password-target-name"></p>

                <div id="reset-password-errors" class="mt-4 hidden rounded-lg border border-red-100 bg-red-50 px-3 py-2 text-sm text-red-700">
                    <ul id="reset-password-errors-list" class="list-disc space-y-1 pl-4"></ul>
                </div>

                <div class="mt-4 space-y-4">
                    <div>
                        <label for="reset-password-new" class="block text-sm font-medium text-gray-700">New password</label>
                        <input id="reset-password-new" type="password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="reset-password-confirm" class="block text-sm font-medium text-gray-700">Confirm password</label>
                        <input id="reset-password-confirm" type="password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    </div>
                    <label class="flex items-start gap-2 text-sm text-gray-600">
                        <input id="reset-password-temporary" type="checkbox" class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span>Temporary - email it to the user and force them to set a new one on next login</span>
                    </label>
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" id="reset-password-cancel" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">Cancel</button>
                    <button type="button" id="reset-password-submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Reset password</button>
                </div>
            </div>
        </div>
        <div id="users-pagination" class="hidden items-center justify-between border-t border-gray-100 px-4 py-3 text-sm text-gray-500">
            <span id="users-pagination-summary"></span>
            <div class="flex gap-2">
                <button type="button" id="users-prev-page" class="rounded-lg border border-gray-200 px-3 py-1.5 font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">Prev</button>
                <button type="button" id="users-next-page" class="rounded-lg border border-gray-200 px-3 py-1.5 font-medium text-gray-600 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40">Next</button>
            </div>
        </div>
    </div>

    <script>
        // Icon+label action buttons (not icon-only - Admin is a
        // lower-traffic, higher-stakes surface where an unlabeled icon
        // is more likely to be misclicked). Inlined as raw SVG strings
        // rather than Blade <x-icons.*> components since this whole
        // table is built from a JS template string, not Blade markup -
        // each one mirrors resources/views/components/icons/*.blade.php
        // exactly so it matches every other icon in the app.
        var ICONS = {
            checkCircle: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="m8.5 12.5 2.5 2.5 4.5-5" /></svg>',
            xCircle: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9" /><path d="m9.5 9.5 5 5" /><path d="m14.5 9.5-5 5" /></svg>',
            logout: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3" /><path d="M15 16l4-4-4-4" /><path d="M19 12H9" /></svg>',
            lock: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="1.8" /><path d="M8 11V7.5a4 4 0 0 1 8 0V11" /></svg>',
            shield: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z" /></svg>',
            trash: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12" /><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg>',
            restore: '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h5" /><path d="M4.5 9a7.5 7.5 0 1 1 1.8 7.7" /></svg>',
        };

        var KEBAB_ICON = '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="1.2" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none" /><circle cx="12" cy="19" r="1.2" fill="currentColor" stroke="none" /></svg>';

        // A row of 5 inline icon+label buttons wrapped awkwardly inside
        // the narrow Actions column (2-3 lines of overlapping text on
        // any viewport under ~1100px). Each row's actions now live in a
        // single kebab dropdown instead - same pattern as Chat's
        // #chat-menu-dropdown - so the column stays one line regardless
        // of how many actions a given row has.
        function adminActionItem(action, icon, label, colorClass) {
            return '<button type="button" data-action="' + action + '" class="admin-row-action flex w-full items-center gap-2 px-3 py-2 text-left text-sm ' + colorClass + ' hover:bg-gray-50">' + icon + '<span>' + label + '</span></button>';
        }

        document.addEventListener('DOMContentLoaded', function () {
            var searchInput = document.getElementById('user-search');
            var statusFilter = document.getElementById('user-status-filter');
            var tbody = document.getElementById('users-table-body');
            var searchTimer = null;
            var currentPage = 1;
            var accountTypeTabs = document.querySelectorAll('.account-type-tab');
            var currentAccountType = '';

            function setActiveAccountTypeTab(tab) {
                accountTypeTabs.forEach(function (t) {
                    t.classList.toggle('bg-brand-50', t === tab);
                    t.classList.toggle('text-brand-700', t === tab);
                    t.classList.toggle('text-gray-500', t !== tab);
                });
            }
            setActiveAccountTypeTab(accountTypeTabs[0]);

            accountTypeTabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    currentAccountType = tab.dataset.accountType;
                    setActiveAccountTypeTab(tab);
                    currentPage = 1;
                    loadUsers();
                });
            });

            function statusBadgeClass(status) {
                return {
                    active: 'bg-emerald-50 text-emerald-700',
                    suspended: 'bg-red-50 text-red-700',
                    inactive: 'bg-gray-100 text-gray-600',
                    pending: 'bg-amber-50 text-amber-700',
                }[status] || 'bg-gray-100 text-gray-600';
            }

            function loadUsers() {
                axios.get('/api/v1/admin/users', {
                    params: { search: searchInput.value || undefined, status: statusFilter.value || undefined, account_type: currentAccountType || undefined, page: currentPage },
                }).then(function (response) {
                    var users = response.data.data;
                    var meta = response.data.meta || {};
                    tbody.innerHTML = '';
                    document.getElementById('users-empty').classList.toggle('hidden', users.length > 0);

                    var pagination = document.getElementById('users-pagination');
                    pagination.classList.toggle('hidden', users.length === 0);
                    document.getElementById('users-pagination-summary').textContent =
                        'Page ' + (meta.current_page || 1) + ' of ' + (meta.last_page || 1) + ' (' + (meta.total || 0) + ' total)';
                    document.getElementById('users-prev-page').disabled = (meta.current_page || 1) <= 1;
                    document.getElementById('users-next-page').disabled = (meta.current_page || 1) >= (meta.last_page || 1);

                    users.forEach(function (user) {
                        var row = document.createElement('tr');
                        row.dataset.userId = user.id;

                        var menuItemsHtml = '';
                        if (user.deleted_at) {
                            menuItemsHtml = adminActionItem('restore', ICONS.restore, 'Restore', 'text-brand-600');
                        } else {
                            menuItemsHtml = (user.status === 'suspended'
                                ? adminActionItem('activate', ICONS.checkCircle, 'Activate', 'text-emerald-600')
                                : adminActionItem('suspend', ICONS.xCircle, 'Suspend', 'text-red-600'))
                                + adminActionItem('force-logout', ICONS.logout, 'Force logout', 'text-gray-700')
                                + adminActionItem('reset-password', ICONS.lock, 'Reset password', 'text-gray-700')
                                + adminActionItem(
                                    user.one_view_super_access ? 'revoke-super-access' : 'grant-super-access',
                                    ICONS.shield,
                                    user.one_view_super_access ? 'Revoke Super Access' : 'Grant Super Access',
                                    'text-indigo-600',
                                )
                                + adminActionItem('delete', ICONS.trash, 'Delete', 'text-red-600');
                        }

                        row.innerHTML =
                            '<td class="px-4 py-3 font-medium text-gray-900">' + user.full_name +
                                (user.is_load_test_account ? ' <span class="rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700">Load-test</span>' : '') +
                            '</td>' +
                            '<td class="px-4 py-3 text-gray-500">' + user.email + '</td>' +
                            '<td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium ' + statusBadgeClass(user.status) + '">' + user.status + (user.deleted_at ? ' (deleted)' : '') + '</span></td>' +
                            '<td class="px-4 py-3 text-gray-500">' + (user.roles || []).join(', ') + '</td>' +
                            '<td class="px-4 py-3">' +
                                '<div class="relative inline-block">' +
                                    '<button type="button" class="admin-row-menu-btn rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Actions">' + KEBAB_ICON + '</button>' +
                                    '<div class="admin-row-menu absolute right-0 top-full z-10 mt-1 hidden w-52 rounded-lg border border-gray-100 bg-white py-1 shadow-lg">' + menuItemsHtml + '</div>' +
                                '</div>' +
                            '</td>';
                        tbody.appendChild(row);
                    });
                }).catch(function () {});
            }

            var resetPasswordModal = document.getElementById('reset-password-modal');
            var resetPasswordUserId = null;

            function closeResetPasswordModal() {
                resetPasswordModal.classList.add('hidden');
                resetPasswordModal.classList.remove('flex');
                document.getElementById('reset-password-new').value = '';
                document.getElementById('reset-password-confirm').value = '';
                document.getElementById('reset-password-temporary').checked = false;
                document.getElementById('reset-password-errors').classList.add('hidden');
            }

            function showResetPasswordErrors(errors) {
                var list = document.getElementById('reset-password-errors-list');
                list.innerHTML = '';
                Object.values(errors || {}).flat().forEach(function (message) {
                    var li = document.createElement('li');
                    li.textContent = message;
                    list.appendChild(li);
                });
                document.getElementById('reset-password-errors').classList.remove('hidden');
            }

            document.getElementById('reset-password-cancel').addEventListener('click', closeResetPasswordModal);

            document.getElementById('reset-password-submit').addEventListener('click', function () {
                axios.post('/api/v1/admin/users/' + resetPasswordUserId + '/reset-password', {
                    new_password: document.getElementById('reset-password-new').value,
                    new_password_confirmation: document.getElementById('reset-password-confirm').value,
                    is_temporary: document.getElementById('reset-password-temporary').checked,
                }).then(function () {
                    closeResetPasswordModal();
                    loadUsers();
                }).catch(function (error) {
                    showResetPasswordErrors(error.response && error.response.data && error.response.data.errors);
                });
            });

            tbody.addEventListener('click', async function (event) {
                var menuBtn = event.target.closest('.admin-row-menu-btn');
                if (menuBtn) {
                    var menu = menuBtn.nextElementSibling;
                    var wasOpen = !menu.classList.contains('hidden');
                    document.querySelectorAll('.admin-row-menu').forEach(function (m) { m.classList.add('hidden'); });
                    if (!wasOpen) menu.classList.remove('hidden');
                    return;
                }

                var button = event.target.closest('[data-action]');
                if (!button) return;
                document.querySelectorAll('.admin-row-menu').forEach(function (m) { m.classList.add('hidden'); });
                var row = button.closest('tr');
                var userId = row.dataset.userId;
                var action = button.dataset.action;

                if (action === 'reset-password') {
                    resetPasswordUserId = userId;
                    document.getElementById('reset-password-target-name').textContent = row.cells[0].textContent.trim();
                    resetPasswordModal.classList.remove('hidden');
                    resetPasswordModal.classList.add('flex');

                    return;
                }

                var request;
                if (action === 'suspend') request = axios.post('/api/v1/admin/users/' + userId + '/suspend');
                else if (action === 'activate') request = axios.post('/api/v1/admin/users/' + userId + '/activate');
                else if (action === 'force-logout') request = axios.post('/api/v1/admin/users/' + userId + '/force-logout');
                else if (action === 'restore') request = axios.post('/api/v1/admin/users/' + userId + '/restore');
                else if (action === 'grant-super-access') request = axios.post('/api/v1/admin/users/' + userId + '/one-view-super-access');
                else if (action === 'revoke-super-access') request = axios.delete('/api/v1/admin/users/' + userId + '/one-view-super-access');
                else if (action === 'delete') {
                    if (!await MeetDialog.confirm('Delete this user?', { danger: true })) return;
                    request = axios.delete('/api/v1/admin/users/' + userId);
                }

                if (request) request.then(loadUsers).catch(async function () { await MeetDialog.alert('Action failed.'); });
            });

            document.addEventListener('click', function (event) {
                if (event.target.closest('.admin-row-menu-btn')) return;
                document.querySelectorAll('.admin-row-menu').forEach(function (m) { m.classList.add('hidden'); });
            });

            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { currentPage = 1; loadUsers(); }, 300);
            });
            statusFilter.addEventListener('change', function () { currentPage = 1; loadUsers(); });

            document.getElementById('users-prev-page').addEventListener('click', function () {
                if (currentPage > 1) { currentPage--; loadUsers(); }
            });
            document.getElementById('users-next-page').addEventListener('click', function () {
                currentPage++; loadUsers();
            });

            loadUsers();
        });
    </script>
</x-layouts.app>
