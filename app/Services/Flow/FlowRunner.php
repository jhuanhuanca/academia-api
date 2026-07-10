<?php

namespace App\Services\Flow;

use App\Models\Flow;
use App\Models\FlowEdge;
use App\Models\FlowNode;
use App\Models\KnowledgeItem;
use App\Services\AI\LunaClient;
use Illuminate\Support\Collection;

class FlowRunner
{
    public function __construct(private readonly LunaClient $luna)
    {
    }

    /**
     * Resuelve el siguiente paso a partir de un trigger (botón / texto / pago).
     *
     * @return array{node: FlowNode|null, edge: FlowEdge|null, actions: array<int, array<string, mixed>>}
     */
    public function advance(Flow $flow, ?FlowNode $current, string $triggerType, ?string $triggerKey = null): array
    {
        $fromId = $current?->id ?? $flow->start_node_id;
        if (! $fromId) {
            return ['node' => null, 'edge' => null, 'actions' => []];
        }

        $fromNode = $current ?? FlowNode::query()->where('flow_id', $flow->id)->find($fromId);

        // Si estamos en start, saltamos al default siguiente automáticamente.
        if ($fromNode && $fromNode->type === 'start' && $triggerType === 'default') {
            $edge = $this->findEdge($flow->id, $fromNode->id, 'default', '');
            if ($edge) {
                $next = FlowNode::query()->find($edge->to_node_id);

                return [
                    'node' => $next,
                    'edge' => $edge,
                    'actions' => $next ? [$this->nodeToAction($next)] : [],
                ];
            }
        }

        $edge = $this->findEdge($flow->id, $fromId, $triggerType, $triggerKey ?? '');
        if (! $edge && $triggerKey) {
            $edge = $this->findEdge($flow->id, $fromId, $triggerType, '');
        }

        if (! $edge) {
            return ['node' => $fromNode, 'edge' => null, 'actions' => []];
        }

        $next = FlowNode::query()->find($edge->to_node_id);

        return [
            'node' => $next,
            'edge' => $edge,
            'actions' => $next ? [$this->nodeToAction($next)] : [],
        ];
    }

    /**
     * Preview: simula un mensaje del lead sobre un nodo ai_reply (o menú).
     *
     * @param  array{tenant_id:int, conversation_id?:int|string, message:string, node_id?:int|null}  $input
     * @return array<string, mixed>
     */
    public function previewWithLuna(Flow $flow, array $input): array
    {
        $node = null;
        if (! empty($input['node_id'])) {
            $node = FlowNode::query()->where('flow_id', $flow->id)->find($input['node_id']);
        }

        if (! $node) {
            $node = FlowNode::query()
                ->where('flow_id', $flow->id)
                ->where('type', 'ai_reply')
                ->first();
        }

        $config = $node?->config ?? [];
        $tags = $config['knowledge_tags'] ?? [];

        $knowledge = KnowledgeItem::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $input['tenant_id'])
            ->where('is_active', true)
            ->get()
            ->filter(function (KnowledgeItem $item) use ($tags) {
                if (empty($tags)) {
                    return true;
                }
                $itemTags = $item->tags ?? [];

                return count(array_intersect($tags, $itemTags)) > 0;
            })
            ->values();

        $transitions = $this->availableTransitions($flow, $node);

        $leadContext = $input['lead_context'] ?? new \stdClass();
        if (is_array($leadContext) && $leadContext === []) {
            $leadContext = new \stdClass();
        }

        $decision = $this->luna->decide([
            'tenant_id' => $input['tenant_id'],
            'conversation_id' => $input['conversation_id'] ?? 'preview',
            'user_message' => $input['message'],
            'current_node' => [
                'type' => $node?->type ?? 'ai_reply',
                'name' => $node?->name,
                'config' => empty($config) ? new \stdClass() : $config,
            ],
            'allowed_knowledge' => $knowledge->map(fn (KnowledgeItem $item) => [
                'title' => $item->title,
                'content' => $item->content,
                'tags' => $item->tags ?? [],
            ])->all(),
            'lead_context' => $leadContext,
            'available_transitions' => $transitions,
            'system_hint' => $config['system_hint'] ?? null,
            'min_confidence' => $config['min_confidence'] ?? null,
        ]);

        $chosen = $decision['chosen_transition'] ?? null;
        $next = null;
        if ($node && $chosen) {
            $advanced = $this->advance($flow, $node, 'ai_transition', $chosen);
            $next = $advanced['node'];
        }

        return [
            'luna' => $decision,
            'current_node' => $node,
            'next_node' => $next,
            'available_transitions' => $transitions,
        ];
    }

    /**
     * @return list<string>
     */
    public function availableTransitions(Flow $flow, ?FlowNode $node): array
    {
        if (! $node) {
            return ['default', 'human'];
        }

        return FlowEdge::query()
            ->where('flow_id', $flow->id)
            ->where('from_node_id', $node->id)
            ->get()
            ->map(function (FlowEdge $edge) {
                return $edge->trigger_key !== '' ? $edge->trigger_key : $edge->trigger_type;
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function nodeToAction(FlowNode $node): array
    {
        return [
            'node_id' => $node->id,
            'node_key' => $node->node_key,
            'type' => $node->type,
            'name' => $node->name,
            'config' => $node->config,
        ];
    }

    private function findEdge(int $flowId, int $fromNodeId, string $triggerType, string $triggerKey): ?FlowEdge
    {
        return FlowEdge::query()
            ->where('flow_id', $flowId)
            ->where('from_node_id', $fromNodeId)
            ->where('trigger_type', $triggerType)
            ->where('trigger_key', $triggerKey)
            ->orderByDesc('priority')
            ->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function graph(Flow $flow): Collection
    {
        $flow->load(['nodes', 'edges']);

        return collect([
            'flow' => $flow->only(['id', 'uuid', 'name', 'status', 'version', 'is_default', 'start_node_id']),
            'nodes' => $flow->nodes,
            'edges' => $flow->edges,
        ]);
    }
}
