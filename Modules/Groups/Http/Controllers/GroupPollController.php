<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Groups\Http\Requests\CreatePollRequest;
use Modules\Groups\Http\Resources\GroupPollResource;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupPoll;
use Modules\Groups\Services\GroupContentService;

class GroupPollController extends Controller
{
    public function __construct(
        private readonly GroupContentService $content,
    ) {
    }

    /**
     * RAS-3 API25 - POST /groups/{groupId}/polls
     */
    public function store(CreatePollRequest $request, Group $group): JsonResponse
    {
        $this->authorize('managePolls', $group);

        $poll = $this->content->createPoll(
            $group,
            $request->user(),
            $request->string('question'),
            $request->input('options'),
            (bool) $request->boolean('allow_multiple_votes'),
            $request->input('closes_at'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Poll created.',
            'data' => new GroupPollResource($poll->load('options.votes', 'creator')),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-3 API26 - POST /groups/{groupId}/polls/{pollId}/vote
     */
    public function vote(Request $request, Group $group, GroupPoll $poll): JsonResponse
    {
        $this->authorize('view', $group);
        abort_unless($poll->group_id === $group->id, 404);

        $request->validate([
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer'],
        ]);

        $this->content->vote($poll, $request->user(), $request->input('option_ids'));

        return response()->json([
            'success' => true,
            'message' => 'Vote recorded.',
            'data' => new GroupPollResource($this->content->results($poll)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API27 - GET /groups/{groupId}/polls/{pollId}
     */
    public function show(Request $request, Group $group, GroupPoll $poll): JsonResponse
    {
        $this->authorize('view', $group);
        abort_unless($poll->group_id === $group->id, 404);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new GroupPollResource($this->content->results($poll)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-3 API28 - PATCH /groups/{groupId}/polls/{pollId}/close
     */
    public function close(Group $group, GroupPoll $poll): JsonResponse
    {
        $this->authorize('managePolls', $group);
        abort_unless($poll->group_id === $group->id, 404);

        $poll = $this->content->closePoll($poll);

        return response()->json([
            'success' => true,
            'message' => 'Poll closed.',
            'data' => new GroupPollResource($this->content->results($poll)),
            'errors' => null,
        ]);
    }
}
