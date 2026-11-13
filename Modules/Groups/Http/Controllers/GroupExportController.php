<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\GroupService;

class GroupExportController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {
    }

    /**
     * RAS-3 API40 - GET /groups/{groupId}/export
     */
    public function __invoke(Group $group): Response
    {
        $this->authorize('manageSettings', $group);

        $csv = $this->groups->exportToCsv($group);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$group->uuid.'-export.csv"',
        ]);
    }
}
