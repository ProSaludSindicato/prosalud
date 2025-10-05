<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreChatbotConversationRequest;
use App\Http\Requests\UpdateChatbotFeedbackRequest;
use App\Models\ChatbotConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
 

class ChatbotConversationController extends Controller
{
    /**
     * Display a listing of conversation logs with optional filters.
     */
    public function index(Request $request)
    {
        $query = ChatbotConversation::query();

        if ($request->filled('client_turn_id')) {
            $query->where('client_turn_id', $request->input('client_turn_id'));
        }
        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->input('conversation_id'));
        }
        if ($request->filled('from_date')) {
            $from = $request->date('from_date');
            $query->whereDate('created_at', '>=', $from->format('Y-m-d'));
        }
        if ($request->filled('to_date')) {
            $to = $request->date('to_date');
            $query->whereDate('created_at', '<=', $to->format('Y-m-d'));
        }
        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('user_question', 'like', "%{$q}%")
                    ->orWhere('bot_answer', 'like', "%{$q}%");
            });
        }

        $conversations = $query
            ->orderByDesc('created_at')
            ->paginate((int) $request->integer('per_page', 15));

        return response()->json($conversations);
    }

    /**
     * Store a newly created conversation log.
     */
    public function store(StoreChatbotConversationRequest $request)
    {
        $data = $request->validated();

        // Enrich with request context
        $data['user_ip'] = $request->ip();
        $data['user_agent'] = $request->userAgent();

        $conversation = ChatbotConversation::create($data);

        return response()->json($conversation, Response::HTTP_CREATED);
    }

    /**
     * Update the feedback of a conversation record.
     */
    public function updateFeedback(UpdateChatbotFeedbackRequest $request, ChatbotConversation $conversation)
    {
        // Ensure the feedback key is present; allow null to clear feedback
        if (!$request->exists('feedback')) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'feedback' => ['The feedback field is required.']
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validated();
        $conversation->feedback = $data['feedback'] ?? null;
        $conversation->save();

        return response()->json($conversation->fresh(), Response::HTTP_OK);
    }

    /**
     * Update the feedback using the client-provided turn identifier.
     */
    public function updateFeedbackByClientTurnId(UpdateChatbotFeedbackRequest $request, string $client_turn_id)
    {
        if (!$request->exists('feedback')) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'feedback' => ['The feedback field is required.']
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $conversation = ChatbotConversation::where('client_turn_id', $client_turn_id)->first();
        if (!$conversation) {
            return response()->json(['message' => 'Conversation turn not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validated();
        $conversation->feedback = $data['feedback'] ?? null;
        $conversation->save();

        return response()->json($conversation->fresh(), Response::HTTP_OK);
    }
}
