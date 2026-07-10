<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Services\Flow\FlowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $flows = Flow::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $flows]);
    }

    public function show(Request $request, Flow $flow, FlowRunner $runner): JsonResponse
    {
        $this->assertTenant($request, $flow->tenant_id);

        return response()->json(['data' => $runner->graph($flow)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $flow = Flow::create([
            'tenant_id' => $request->user()->tenant_id,
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'draft',
            'version' => 1,
            'is_default' => (bool) ($data['is_default'] ?? false),
        ]);

        if ($flow->is_default) {
            Flow::query()
                ->where('tenant_id', $flow->tenant_id)
                ->where('id', '!=', $flow->id)
                ->update(['is_default' => false]);
        }

        return response()->json(['data' => $flow], 201);
    }

    public function update(Request $request, Flow $flow): JsonResponse
    {
        $this->assertTenant($request, $flow->tenant_id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('is_default', $data) && $data['is_default']) {
            Flow::query()
                ->where('tenant_id', $flow->tenant_id)
                ->where('id', '!=', $flow->id)
                ->update(['is_default' => false]);
        }

        $flow->update($data);

        return response()->json(['data' => $flow->fresh()]);
    }

    public function publish(Request $request, Flow $flow): JsonResponse
    {
        $this->assertTenant($request, $flow->tenant_id);

        if ($flow->is_default) {
            Flow::query()
                ->where('tenant_id', $flow->tenant_id)
                ->where('id', '!=', $flow->id)
                ->update(['is_default' => false]);
        }

        $flow->update([
            'status' => 'published',
            'version' => $flow->version + ($flow->status === 'published' ? 1 : 0),
            'published_at' => now(),
            'is_default' => true,
        ]);

        return response()->json(['data' => $flow->fresh()]);
    }

    public function syncGraph(Request $request, Flow $flow): JsonResponse
    {
        $this->assertTenant($request, $flow->tenant_id);

        $payload = $request->validate([
            'start_node_key' => ['nullable', 'string', 'max:64'],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.node_key' => ['required', 'string', 'max:64'],
            'nodes.*.type' => ['required', 'string', 'max:40'],
            'nodes.*.name' => ['required', 'string', 'max:120'],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.position_x' => ['nullable', 'integer'],
            'nodes.*.position_y' => ['nullable', 'integer'],
            'edges' => ['nullable', 'array'],
            'edges.*.from_node_key' => ['required', 'string', 'max:64'],
            'edges.*.to_node_key' => ['required', 'string', 'max:64'],
            'edges.*.trigger_type' => ['required', 'string', 'max:40'],
            'edges.*.trigger_key' => ['nullable', 'string', 'max:80'],
            'edges.*.priority' => ['nullable', 'integer'],
        ]);

        DB::transaction(function () use ($flow, $payload) {
            FlowEdge::query()->where('flow_id', $flow->id)->delete();
            FlowNode::query()->where('flow_id', $flow->id)->delete();

            $map = [];
            foreach ($payload['nodes'] as $node) {
                $created = FlowNode::create([
                    'flow_id' => $flow->id,
                    'node_key' => $node['node_key'],
                    'type' => $node['type'],
                    'name' => $node['name'],
                    'config' => $node['config'] ?? [],
                    'position_x' => $node['position_x'] ?? 0,
                    'position_y' => $node['position_y'] ?? 0,
                ]);
                $map[$node['node_key']] = $created->id;
            }

            foreach ($payload['edges'] ?? [] as $edge) {
                if (! isset($map[$edge['from_node_key']], $map[$edge['to_node_key']])) {
                    continue;
                }
                FlowEdge::create([
                    'flow_id' => $flow->id,
                    'from_node_id' => $map[$edge['from_node_key']],
                    'to_node_id' => $map[$edge['to_node_key']],
                    'trigger_type' => $edge['trigger_type'],
                    'trigger_key' => $edge['trigger_key'] ?? '',
                    'priority' => $edge['priority'] ?? 0,
                ]);
            }

            $startKey = $payload['start_node_key'] ?? null;
            $flow->update([
                'start_node_id' => $startKey && isset($map[$startKey]) ? $map[$startKey] : ($map['start'] ?? null),
                'status' => $flow->status === 'published' ? 'draft' : $flow->status,
            ]);
        });

        return response()->json([
            'data' => app(FlowRunner::class)->graph($flow->fresh()),
        ]);
    }

    public function preview(Request $request, Flow $flow, FlowRunner $runner): JsonResponse
    {
        $this->assertTenant($request, $flow->tenant_id);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'node_id' => ['nullable', 'integer'],
        ]);

        $result = $runner->previewWithLuna($flow, [
            'tenant_id' => $request->user()->tenant_id,
            'conversation_id' => 'preview-'.$request->user()->id,
            'message' => $data['message'],
            'node_id' => $data['node_id'] ?? null,
        ]);

        return response()->json(['data' => $result]);
    }

    private function assertTenant(Request $request, int $tenantId): void
    {
        abort_if($request->user()->tenant_id !== $tenantId, 404);
    }
}
