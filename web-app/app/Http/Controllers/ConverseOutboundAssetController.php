<?php

namespace App\Http\Controllers;

use App\Actions\ConverseOutboundAssets;
use App\Actions\RecordConverseAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConverseOutboundAssetController extends Controller
{
    public function upload(Request $request, ConverseOutboundAssets $assets, RecordConverseAudit $audit, int $collection): RedirectResponse
    {
        $this->collection($request, $collection);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'attachment' => ['required', 'file', 'max:'.ConverseOutboundAssets::MAX_UPLOAD_KIB],
        ]);
        $asset = $assets->store($data['attachment'], $request->user()->id, collection: $collection, title: $data['title']);
        $audit($request->user()->id, 'knowledge.outbound_asset_uploaded', 'outbound_asset', $asset->id, ['collection_id' => $collection]);

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    public function update(Request $request, RecordConverseAudit $audit, int $collection, int $asset): RedirectResponse
    {
        $this->collection($request, $collection);
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $audit, $collection, $asset, $data): void {
            $scope = DB::table('cv_outbound_assets')->where('id', $asset)->where('collection_id', $collection)->whereNull('conversation_id');
            abort_unless($scope->lockForUpdate()->first(), 404);
            $scope->update($data);
            $audit($request->user()->id, 'knowledge.outbound_asset_reviewed', 'outbound_asset', $asset, $data);
        });

        return to_route('converse.knowledge', ['collection' => $collection]);
    }

    private function collection(Request $request, int $collection): void
    {
        abort_if($request->user()->is_demo, 403);
        abort_unless(DB::table('cv_knowledge_collections')->where('id', $collection)->where('is_demo', false)->exists(), 404);
    }
}
