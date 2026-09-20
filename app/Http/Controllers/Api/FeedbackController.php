<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    /**
     * POST /api/feedback/submit
     * 提交意见反馈（需要登录：带上 user_id 便于后台回访）
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string|max:1000',
            'contact' => 'nullable|string|max:100',
        ]);

        $feedback = Feedback::create([
            'user_id' => $request->user()?->id,
            'content' => $validated['content'],
            'contact' => $validated['contact'] ?? null,
        ]);

        return response()->json([
            'code' => 0,
            'msg'  => '感谢反馈！',
            'data' => ['id' => $feedback->id],
        ], 201);
    }
}
