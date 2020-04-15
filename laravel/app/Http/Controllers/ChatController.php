<?php

namespace App\Http\Controllers;

use App\Message;
use Auth;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChatController extends Controller
{
    public function getChatList(Request $request)
    {
        $receiver = $request->$receiver;
        $sender = Auth::user()->id;
        $m1 = Message::where('sender_id', $sender)->where('receiver_id', $receiver)->get();
        $m2 = Message::where('sender_id', $receiver)->where('receiver_id', $sender)->get();
        return $m1->concat($m2)->sortBy('id')->values()->all();
    }

    public function sendMessage(Request $request)
    {
        $content = $content->content;
        $receiver = $request->$receiver;
        $sender = Auth::user()->id;
        $message = Message::create([
              'content' => $content,
              'sender_id' => $sender,
              'receiver_id' => $receiver
            ]);
        broadcast(new MessageSent($message))->toOthers();
        return ['status' => 'Message Sent!'];
    }
}
