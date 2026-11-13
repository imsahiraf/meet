<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Settings > Meet Insights: link this Meet account to an Insights login (merge), or remove the link. */
class InsightsLinkController extends Controller
{
    public function show()
    {
        return view('authentication::insights-link', [
            'links' => DB::table('insights_links')->where('user_id', Auth::id())->orderBy('insights_email')->get(),
            'linkUrl' => rtrim((string) config('services.insights.url'), '/') . '/go/meet?link=1',
        ]);
    }

    public function destroy(int $id)
    {
        DB::table('insights_links')->where('user_id', Auth::id())->where('id', $id)->delete();

        return back()->with('status', 'Link removed.');
    }
}
