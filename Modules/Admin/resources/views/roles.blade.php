<x-layouts.app title="Roles & permissions - Meet">
    @include('admin::partials.nav')

    <div class="mb-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Create a role</h2>
        <form id="create-role-form" class="space-y-3">
            <div class="flex gap-2">
                <input type="text" id="new-role-name" placeholder="Role name" required class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                <input type="text" id="new-role-description" placeholder="Description (optional)" class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div id="new-role-permissions" class="flex flex-wrap gap-3 text-sm text-gray-600"></div>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Create role</button>
        </form>
    </div>

    <div id="roles-list" class="space-y-3"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var allPermissions = [];

            function permissionCheckboxes(container, selected) {
                container.innerHTML = '';
                allPermissions.forEach(function (perm) {
                    var label = document.createElement('label');
                    label.className = 'flex items-center gap-1.5';
                    var checked = (selected || []).indexOf(perm.permission_key) !== -1;
                    label.innerHTML = '<input type="checkbox" value="' + perm.permission_key + '" ' + (checked ? 'checked' : '') + ' class="rounded border-gray-300 text-brand-600"> ' + perm.permission_name;
                    container.appendChild(label);
                });
            }

            function loadPermissions() {
                return axios.get('/api/v1/admin/permissions').then(function (response) {
                    allPermissions = response.data.data;
                    permissionCheckboxes(document.getElementById('new-role-permissions'), []);
                });
            }

            function loadRoles() {
                axios.get('/api/v1/admin/roles').then(function (response) {
                    var list = document.getElementById('roles-list');
                    list.innerHTML = '';

                    response.data.data.forEach(function (role) {
                        var card = document.createElement('div');
                        card.className = 'rounded-2xl border border-gray-100 bg-white p-5 shadow-sm';
                        card.dataset.roleId = role.id;

                        var header = document.createElement('div');
                        header.className = 'mb-3 flex items-center justify-between';
                        header.innerHTML = '<div><p class="font-semibold text-gray-900">' + role.name + '</p><p class="text-xs text-gray-400">' + (role.description || '') + ' &middot; ' + role.user_count + ' member(s)</p></div>';

                        var actions = document.createElement('div');
                        actions.className = 'flex gap-2 text-sm';
                        if (role.name !== 'Super Admin') {
                            var deleteBtn = document.createElement('button');
                            deleteBtn.type = 'button';
                            deleteBtn.className = 'inline-flex items-center gap-1 text-red-600 hover:underline';
                            deleteBtn.innerHTML = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12" /><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" /></svg><span>Delete</span>';
                            deleteBtn.addEventListener('click', async function () {
                                if (!await MeetDialog.confirm('Delete role "' + role.name + '"?', { danger: true })) return;
                                axios.delete('/api/v1/admin/roles/' + role.id).then(loadRoles).catch(async function () { await MeetDialog.alert('Could not delete role.'); });
                            });
                            actions.appendChild(deleteBtn);
                        }
                        header.appendChild(actions);
                        card.appendChild(header);

                        var permsContainer = document.createElement('div');
                        permsContainer.className = 'flex flex-wrap gap-3 text-sm text-gray-600';
                        permissionCheckboxes(permsContainer, role.permissions);
                        card.appendChild(permsContainer);

                        var saveBtn = document.createElement('button');
                        saveBtn.type = 'button';
                        saveBtn.textContent = 'Save permissions';
                        saveBtn.className = 'mt-3 rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50';
                        saveBtn.addEventListener('click', function () {
                            var checked = [...permsContainer.querySelectorAll('input:checked')].map(function (el) { return el.value; });
                            axios.patch('/api/v1/admin/roles/' + role.id, { permissions: checked }).then(loadRoles).catch(async function () { await MeetDialog.alert('Could not save permissions.'); });
                        });
                        card.appendChild(saveBtn);

                        list.appendChild(card);
                    });
                }).catch(function () {});
            }

            document.getElementById('create-role-form').addEventListener('submit', function (event) {
                event.preventDefault();
                var permissions = [...document.querySelectorAll('#new-role-permissions input:checked')].map(function (el) { return el.value; });

                axios.post('/api/v1/admin/roles', {
                    name: document.getElementById('new-role-name').value,
                    description: document.getElementById('new-role-description').value || null,
                    permissions: permissions,
                }).then(function () {
                    document.getElementById('new-role-name').value = '';
                    document.getElementById('new-role-description').value = '';
                    loadRoles();
                }).catch(async function (error) {
                    await MeetDialog.alert((error.response && error.response.data && error.response.data.message) || 'Could not create role.');
                });
            });

            loadPermissions().then(loadRoles);
        });
    </script>
</x-layouts.app>
