<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchAttributeController
{
    /**
     * List registered search attribute definitions.
     */
    public function index(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        // System search attributes are always available
        $systemAttributes = [
            'WorkflowType' => 'keyword',
            'WorkflowId' => 'keyword',
            'RunId' => 'keyword',
            'Status' => 'keyword',
            'StartTime' => 'datetime',
            'CloseTime' => 'datetime',
            'TaskQueue' => 'keyword',
            'BuildId' => 'keyword',
        ];

        // TODO: Load custom search attributes from namespace config

        return response()->json([
            'system_attributes' => $systemAttributes,
            'custom_attributes' => [],
        ]);
    }

    /**
     * Register a custom search attribute.
     */
    public function store(Request $request): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'type' => ['required', 'string', 'in:keyword,text,int,double,bool,datetime,keyword_list'],
        ]);

        // TODO: Register custom search attribute for namespace

        return response()->json([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'outcome' => 'created',
        ], 201);
    }

    /**
     * Remove a custom search attribute.
     */
    public function destroy(Request $request, string $name): JsonResponse
    {
        $namespace = $request->attributes->get('namespace');

        // TODO: Remove custom search attribute

        return response()->json([
            'name' => $name,
            'outcome' => 'deleted',
        ]);
    }
}
