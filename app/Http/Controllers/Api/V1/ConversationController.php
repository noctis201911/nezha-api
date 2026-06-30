<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\DeliveryMan;
use App\Models\UserInfo;
use App\Models\Message;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\Vendor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    public function messages_store(Request $request)
    {
        if ($request->has('image')) {
            $validator = Validator::make($request->all(), [
                'image.*' => 'max:5120',
            ],[
                'image.*.max' => translate('Max File Upload limit is 5mb')
            ]);
            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)],403);
            }

            $image_name=[];
            foreach($request->file('image') as $key=>$img)
            {
                $name = Helpers::upload(dir:'conversation/', format:$img->getClientOriginalExtension(),image: $img);
                array_push($image_name,['img'=>$name, 'storage'=> Helpers::getDisk()]);
            }
        } else {
            $image_name = null;
        }
        $limit = $request['limit']??10;
        $offset = $request['offset']??1;
        $fcm_token_web = null;

        $sender = UserInfo::where('user_id', $request?->user()?->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->user_id = $request?->user()?->id;
            $sender->f_name = $request?->user()?->f_name;
            $sender->l_name = $request?->user()?->l_name;
            $sender->phone = $request?->user()?->phone;
            $sender->email = $request?->user()?->email;
            $sender->image = $request?->user()?->image;
            $sender->save();
        }

        if($request->conversation_id){
            $conversation = Conversation::find($request->conversation_id);

            if(!$conversation || ($conversation->sender_id != $sender->id && $conversation->receiver_id != $sender->id)){
                return response()->json(['errors' => [['code' => 'conversation', 'message' => translate('messages.not_found')]]], 403);
            }
            if($conversation->sender_id == $sender->id){
                $receiver_id = $conversation->receiver_id;
                $receiver = UserInfo::find($receiver_id);
                if($receiver->vendor_id){
                    $vendor = Vendor::find($receiver->vendor_id);
                    $fcm_token=$vendor->firebase_token;
                    $fcm_token_web=$vendor->fcm_token_web;
                }elseif($receiver->deliveryman_id){
                    $delivery_man = DeliveryMan::find($receiver->deliveryman_id);
                    $fcm_token=$delivery_man->fcm_token;
                }elseif($receiver->admin_id){
                    $receiver_id = 0;
                }
            }else{
                $receiver_id =$conversation->sender_id;
                $receiver = UserInfo::find($receiver_id);
                if($receiver->vendor_id){
                    $vendor = Vendor::find($receiver->vendor_id);
                    $fcm_token=$vendor->firebase_token;
                    $fcm_token_web=$vendor->fcm_token_web;
                }elseif($receiver->deliveryman_id){
                    $delivery_man = DeliveryMan::find($receiver->deliveryman_id);
                    $fcm_token=$delivery_man->fcm_token;
                }elseif($receiver->admin_id){
                    $receiver_id = 0;
                }
            }
        }else{
            if($request->receiver_type == 'admin'){
                $receiver_id = 0;
            }else if($request->receiver_type == 'vendor'){
                $receiver = UserInfo::where('vendor_id',$request->receiver_id)->first();
                $vendor = Vendor::find($request->receiver_id);
                if(!$receiver){
                    $receiver = new UserInfo();
                    $receiver->vendor_id = $vendor->id;
                    $receiver->f_name = $vendor?->restaurants[0]?->getRawOriginal('name');
                    $receiver->l_name = '';
                    $receiver->phone = $vendor->phone;
                    $receiver->email = $vendor->email;
                    $receiver->image = $vendor?->restaurants[0]?->logo;
                    $receiver->save();
                }

                $receiver_id = $receiver->id;
                $fcm_token=$vendor->firebase_token;
                $fcm_token_web=$vendor->fcm_token_web;

            }else if($request->receiver_type == 'delivery_man'){
                $receiver = UserInfo::where('deliveryman_id',$request->receiver_id)->first();
                $delivery_man = DeliveryMan::find($request->receiver_id);

                if(!$receiver){
                    $receiver = new UserInfo();
                    $receiver->deliveryman_id = $delivery_man->id;
                    $receiver->f_name = $delivery_man->f_name;
                    $receiver->l_name = $delivery_man->l_name;
                    $receiver->phone = $delivery_man->phone;
                    $receiver->email = $delivery_man->email;
                    $receiver->image = $delivery_man->image;
                    $receiver->save();
                }

                $receiver_id = $receiver->id;
                $fcm_token=$delivery_man->fcm_token;
            }

            $conversation = Conversation::WhereConversation($sender->id,$receiver_id)->first();
        }

        if(!$conversation){
            $conversation = new Conversation;
            $conversation->sender_id = $sender->id;
            $conversation->sender_type = 'customer';
            $conversation->receiver_id = $receiver_id;
            $conversation->receiver_type = $request->receiver_type;
            $conversation->unread_message_count = 0;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();
            $conversation= Conversation::find($conversation->id);
        }

        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->message = $request->message;
        if($image_name && count($image_name)>0){
            $message->file = json_encode($image_name, JSON_UNESCAPED_SLASHES);
        }

        // 哪吒: 顾客「一键发送订单卡片」——校验引用订单必须本人下单 + 属于本会话商家(IDOR 防越权)。
        // 非商家会话(客服/骑手)或订单不匹配则拒绝；纯卡片消息允许 message 为空。
        $message->order_id = null;
        if (!empty($request->order_id)) {
            $convCounterpartId = ($conversation->sender_id == $sender->id)
                ? $conversation->receiver_id
                : $conversation->sender_id;
            $convVendorId = $convCounterpartId ? UserInfo::find($convCounterpartId)?->vendor_id : null;
            if ($convVendorId) {
                $refOrder = Order::where('id', $request->order_id)
                    ->where('user_id', $request?->user()?->id)
                    ->first();
                $orderVendorId = $refOrder ? Restaurant::where('id', $refOrder->restaurant_id)->value('vendor_id') : null;
                if ($refOrder && (int) $orderVendorId === (int) $convVendorId) {
                    $message->order_id = $refOrder->id;
                } else {
                    return response()->json(['errors' => [['code' => 'order', 'message' => translate('messages.not_found')]]], 403);
                }
            }
        }
        try {
            if($message->save())
            $conversation->unread_message_count = $conversation->unread_message_count? $conversation->unread_message_count+1:1;
            $conversation->last_message_id=$message->id;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();
            {
                if($request->receiver_type == 'admin' || $receiver_id == 0){
                    $data = [
                        'title' =>translate('messages.message'),
                        'description' =>translate('messages.message_description'),
                        'order_id' => '',
                        'image' => '',
                        'message' => json_encode($message) ,
                        'type'=> 'message'
                    ];
                    Helpers::send_push_notif_to_topic($data,'admin_message','message');
                }else if($request->receiver_type == 'vendor' || $request->receiver_type == 'delivery_man'){
                    $data = [
                        'title' =>translate('messages.message'),
                        'description' =>translate('messages.message_description'),
                        'order_id' => '',
                        'image' => '',
                        'message' => json_encode($message) ,
                        'type'=> 'message',
                        'conversation_id'=> $conversation->id,
                        'sender_type'=> 'user'
                    ];
                    Helpers::send_push_notif_to_device($fcm_token, $data);
                    if($fcm_token_web){
                        Helpers::send_push_notif_to_device($fcm_token_web, $data);
                    }
                }
            }

        } catch (\Exception $e) {
            info($e->getMessage());
        }

        // Nezha AI customer service: auto-reply / handoff for messages sent to platform support (admin).
        if (($request->receiver_type == 'admin' || (isset($receiver_id) && $receiver_id == 0)) && isset($message)) {
            $nezhaCsConv = $conversation; $nezhaCsUser = $request?->user(); $nezhaCsMsg = $message;
            dispatch(function () use ($nezhaCsConv, $nezhaCsUser, $nezhaCsMsg) {
                try {
                    \App\CentralLogics\NezhaCsAssistant::handleCustomerMessage($nezhaCsConv, $nezhaCsUser, $nezhaCsMsg);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('nezha cs hook failed: ' . $e->getMessage());
                }
            })->afterResponse();
        }

        $messages = Message::with('order')->where(['conversation_id' => $conversation->id])->latest()->paginate($limit, ['*'], 'page', $offset);

        $conv = Conversation::with('sender','receiver','last_message.order')->find($conversation->id);

        if($conv->sender_type == 'vendor' && $conversation->sender){
            $vd = Vendor::find($conv->sender->vendor_id);
            $order = Order::where('user_id',$request?->user()?->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->receiver_type == 'vendor' && $conversation->receiver){
            $vd = Vendor::find($conv->receiver->vendor_id);
            $order = Order::where('user_id',$request?->user()?->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->sender_type == 'delivery_man' && $conversation->sender){
            $user2 = DeliveryMan::find($conv->sender->deliveryman_id);
            $order = Order::where('user_id',$request?->user()?->id)->where('delivery_man_id', $user2->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->receiver_type == 'delivery_man' && $conversation->receiver){
            $user2 = DeliveryMan::find($conv->receiver->deliveryman_id);
            $order = Order::where('user_id',$request?->user()?->id)->where('delivery_man_id', $user2->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }
        else{
            $order=1;
        }


        $data =  [
            'total_size' => intval($messages->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'status' => ($order>0)?true:false,
            'message' => 'successfully sent!',
            'messages' => $messages->items(),
            'conversation' => $conv,
        ];
        return response()->json($data, 200);
    }

    // 哪吒: 顾客聊天「订单选择抽屉」数据源——列出本人与该商家近 3 天的订单(供一键发卡片)。
    // 作用域严格按登录用户 user_id 派生，无 IDOR；只读，不碰资金。
    public function chat_orders(Request $request)
    {
        $userId = $request?->user()?->id;
        if (!$userId || !$request->vendor_id) {
            return response()->json(['orders' => []], 200);
        }

        $restaurantIds = Restaurant::where('vendor_id', $request->vendor_id)->pluck('id');
        if ($restaurantIds->isEmpty()) {
            return response()->json(['orders' => []], 200);
        }

        $orders = Order::with('OrderReference')
            ->where('user_id', $userId)
            ->whereIn('restaurant_id', $restaurantIds)
            ->where('created_at', '>=', Carbon::now()->subDays(3))
            ->whereNotIn('order_type', ['pos'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_status' => $order->order_status,
                    'order_type' => $order->order_type,
                    'order_amount' => $order->order_amount,
                    'token_number' => $order->OrderReference?->token_number,
                    'created_at' => $order->created_at ? (string) $order->created_at : null,
                ];
            });

        return response()->json(['orders' => $orders], 200);
    }

    public function chat_image(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'image' => 'required|max:2048'

        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->has('image')) {
            $image_name = Helpers::upload(dir:'conversation/', format:$request->file('image')->getClientOriginalExtension(),image: $request->file('image'));
        } else {
            $image_name = 'def.png';
        }

        $url = dynamicStorage('storage/app/public/conversation') . '/' . $image_name;

        return response()->json(['image_url' => $url], 200);
    }


    public function conversations(Request $request)
    {
        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $sender = UserInfo::where('user_id', $request?->user()?->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->user_id = $request?->user()?->id;
            $sender->f_name = $request?->user()?->f_name;
            $sender->l_name = $request?->user()?->l_name;
            $sender->phone = $request?->user()?->phone;
            $sender->email = $request?->user()?->email;
            $sender->image = $request?->user()?->image;
            $sender->save();
        }

        $conversations = Conversation::with('sender','receiver','last_message.order')
        ->where(function($q) use($sender){
                    $q->where(['sender_id' => $sender->id])->orWhere(['receiver_id' => $sender->id]);
                })
        ->when(isset($request->type) , function($query) use($request) {
            $query->where(function($q) use($request){
                $q->where('receiver_type', $request->type)->where('sender_type','customer')
                    ->orWhere(function($q) use($request){
                        $q->where('sender_type', $request->type)->where('receiver_type','customer');
                });
            });
        })
        ->orderBy('last_message_time', 'DESC')->paginate($limit, ['*'], 'page', $offset);

        // Fix fake red dot: unread_message_count is a single directional counter
        // (it belongs to the recipient of the latest message). When the customer
        // sent the last message and the other party has not replied, that count is
        // the OTHER party's unread and must not inflate the customer's own badge.
        // Keep unread only when the last message is from the other party; otherwise
        // zero it for this viewer (output only, the DB column is left untouched).
        $conv_items = $conversations->items();
        foreach ($conv_items as $cv) {
            $lm = $cv->last_message;
            if (!$lm || $lm->sender_id == $sender->id) {
                $cv->unread_message_count = 0;
            }
        }

        $data =  [
            'type'=>$request->type ?? null,
            'total_size' => intval($conversations->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'conversations' => $conv_items
        ];
        return response()->json($data, 200);
    }

    public function get_searched_conversations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $key = explode(' ', $request['name']);

        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $sender = UserInfo::where('user_id', $request?->user()?->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->user_id = $request?->user()?->id;
            $sender->f_name = $request?->user()?->f_name;
            $sender->l_name = $request?->user()?->l_name;
            $sender->phone = $request?->user()?->phone;
            $sender->email = $request?->user()?->email;
            $sender->image = $request?->user()?->image;
            $sender->save();
        }

        $conversations = Conversation::with('sender','receiver','last_message.order')->WhereUser($sender->id)->where(function($qu)use($key){
                    $qu->whereHas('sender',function($query)use($key){
                    foreach ($key as $value) {
                        $query->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%");
                    }
                })
                ->orWhereHas('receiver',function($query1)use($key){
                    foreach ($key as $value) {
                        $query1->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%");
                    }
                });
            })
            ->when(isset($request->type) , function($query) use($request) {
                $query->where(function($q) use($request){
                    $q->where('receiver_type', $request->type)->where('sender_type','customer')
                        ->orWhere(function($q) use($request){
                            $q->where('sender_type', $request->type)->where('receiver_type','customer');
                    });
                });
            }) ;

        $conversations = $conversations->orderBy('last_message_time', 'DESC')->paginate($limit, ['*'], 'page', $offset);

        $data =  [
            'total_size' => intval($conversations->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'conversations' => $conversations->items()
        ];
        return response()->json($data, 200);
    }

    public function messages(Request $request)
    {
        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $user = UserInfo::where('user_id', $request?->user()?->id)->first();
        if(!$user){
            $user = new UserInfo();
            $user->user_id = $request?->user()?->id;
            $user->f_name = $request?->user()?->f_name;
            $user->l_name = $request?->user()?->l_name;
            $user->phone = $request?->user()?->phone;
            $user->email = $request?->user()?->email;
            $user->image = $request?->user()?->image;
            $user->save();
        }

        $conversation = null;
        if($request->conversation_id){
            $conversation = Conversation::with(['sender','receiver','last_message'])->find($request->conversation_id);
        }else if($request->has('admin_id')){
            \App\CentralLogics\NezhaCsAssistant::seedWelcome($request->user()); // 阶段C: 首次打开客服播欢迎语
            $conversation = Conversation::with(['sender','receiver','last_message'])->WhereConversation($user->id,0)->first();
            $order=0;
        }else if($request->vendor_id){
            $vendor = UserInfo::where('vendor_id', $request->vendor_id)->first();
            if(!$vendor){
                $vd = Vendor::find($request->vendor_id);
                $vendor = new UserInfo();
                $vendor->vendor_id = $vd->id;
                $vendor->f_name = $vd?->restaurants[0]?->name;
                $vendor->l_name = '';
                $vendor->phone = $vd->phone;
                $vendor->email = $vd->email;
                $vendor->image = $vd?->restaurants[0]?->logo;
                $vendor->save();
            }
            $conversation = Conversation::with(['sender','receiver','last_message'])->WhereConversation($user->id,$vendor->id)->first();
        }else if($request->delivery_man_id){
            $dm = UserInfo::where('deliveryman_id', $request->delivery_man_id)->first();
            if(!$dm){
                $user2 = DeliveryMan::find($request->delivery_man_id);
                $dm = new UserInfo();
                $dm->deliveryman_id = $user2->id;
                $dm->f_name = $user2->f_name;
                $dm->l_name = $user2->l_name;
                $dm->phone = $user2->phone;
                $dm->email = $user2->email;
                $dm->image = $user2->image;
                $dm->save();
            }
            $conversation = Conversation::with(['sender','receiver','last_message'])->WhereConversation($user->id,$dm->id)->first();
        }

        if(isset($conversation)){
            if($conversation->sender_id != $user->id && $conversation->receiver_id != $user->id){
                return response()->json(['errors' => [['code' => 'conversation', 'message' => translate('messages.not_found')]]], 403);
            }
            if($conversation->sender_type == 'vendor' && $conversation->sender){
                $vd = Vendor::find($conversation->sender->vendor_id);
                $order = Order::where('user_id',$user->user_id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->receiver_type == 'vendor' && $conversation->receiver){
                $vd = Vendor::find($conversation->receiver->vendor_id);
                $order = Order::where('user_id',$user->user_id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->sender_type == 'delivery_man' && $conversation->sender){
                $user2 = DeliveryMan::find($conversation->sender->deliveryman_id);
                $order = Order::where('user_id',$user->user_id)->where('delivery_man_id', $user2->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->receiver_type == 'delivery_man' && $conversation->receiver){
                $user2 = DeliveryMan::find($conversation->receiver->deliveryman_id);
                $order = Order::where('user_id',$user->user_id)->where('delivery_man_id', $user2->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }
            else{
                $order=1;
            }

            $lastmessage = $conversation->last_message;
            if($lastmessage && $lastmessage->sender_id != $user->id ) {
                $conversation->unread_message_count = 0;
                $conversation->save();
            }
            Message::where(['conversation_id' => $conversation->id])->where('sender_id','!=',$user->id)->update(['is_seen' => 1]);
            $messages = Message::with('order')->where(['conversation_id' => $conversation->id])->latest()->paginate($limit, ['*'], 'page', $offset);
        }else{
            $messages =[];
            $order=0;
        }


        $data =  [
            'total_size' => $messages? intval($messages->total()):0,
            'limit' => intval($limit),
            'offset' => intval($offset),
            'status' => ($order > 0)?true:false,
            'messages' => $messages? $messages->items():[],
            'conversation' => $conversation
        ];
        return response()->json($data, 200);
    }

    // 顾客「全部已读」：把本人参与的全部会话(商家+客服)标记已读。
    // 作用域按本人 UserInfo.id 派生(无越权)；逐会话镜像 messages() 的单会话已读逻辑。
    public function mark_all_read(Request $request)
    {
        $user = UserInfo::where('user_id', $request?->user()?->id)->first();
        if (!$user) {
            return response()->json(['conversations_cleared' => 0, 'messages_marked' => 0], 200);
        }

        $conversations = Conversation::with('last_message')
            ->where(function ($q) use ($user) {
                $q->where('sender_id', $user->id)->orWhere('receiver_id', $user->id);
            })->get();

        $cleared = 0;
        foreach ($conversations as $conversation) {
            // 仅当最后一条来自对方时清未读计数(unread_message_count 是单计数器，
            // 最后一条是顾客自己发的代表对方未读，不能在此清零)
            $lastmessage = $conversation->last_message;
            if ($lastmessage && $lastmessage->sender_id != $user->id && $conversation->unread_message_count) {
                $conversation->unread_message_count = 0;
                $conversation->save();
                $cleared++;
            }
        }

        $conversationIds = $conversations->pluck('id')->all();
        $marked = 0;
        if (count($conversationIds)) {
            $marked = Message::whereIn('conversation_id', $conversationIds)
                ->where('sender_id', '!=', $user->id)
                ->where('is_seen', 0)
                ->update(['is_seen' => 1]);
        }

        return response()->json([
            'conversations_cleared' => $cleared,
            'messages_marked' => $marked,
        ], 200);
    }

    public function dm_messages_store(Request $request)
    {

        if ($request->has('image')) {

            $validator = Validator::make($request->all(), [
                'image.*' => 'max:5120',
            ],[
                'image.*.max' => translate('Max File Upload limit is 5mb')
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)],403);
            }

            $image_name=[];
            foreach($request->file('image') as $key=>$img)
            {

                $name = Helpers::upload('conversation/', $img->getClientOriginalExtension(), $img);
                array_push($image_name,['img'=>$name, 'storage'=> Helpers::getDisk()]);
            }
        } else {
            $image_name = null;
        }

        $limit = $request['limit']??10;
        $offset = $request['offset']??1;
        $fcm_token_web = null;
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $sender = UserInfo::where('deliveryman_id', $dm->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->deliveryman_id = $dm->id;
            $sender->f_name = $dm->f_name;
            $sender->l_name = $dm->l_name;
            $sender->phone = $dm->phone;
            $sender->email = $dm->email;
            $sender->image = $dm->image;
            $sender->save();
        }

        if($request->conversation_id){
            $conversation = Conversation::find($request->conversation_id);

            if(!$conversation || ($conversation->sender_id != $sender->id && $conversation->receiver_id != $sender->id)){
                return response()->json(['errors' => [['code' => 'conversation', 'message' => translate('messages.not_found')]]], 403);
            }
            if($conversation->sender_id == $sender->id){
                $receiver_id = $conversation->receiver_id;
                $receiver = UserInfo::find($receiver_id);
                if($receiver->vendor_id){
                    $vendor = Vendor::find($receiver->vendor_id);
                    $fcm_token=$vendor->firebase_token;
                    $fcm_token_web = "restaurant_panel_{$vendor?->restaurants[0]?->id}_message";
                }elseif($receiver->user_id){
                    $user = User::find($receiver->user_id);
                    $fcm_token=$user->cm_firebase_token;
                }
            }else{
                $receiver_id =$conversation->sender_id;
                $receiver = UserInfo::find($receiver_id);
                if($receiver->vendor_id){
                    $vendor = Vendor::find($receiver->vendor_id);
                    $fcm_token=$vendor->firebase_token;
                    $fcm_token_web="restaurant_panel_{$vendor?->restaurants[0]?->id}_message";
                }elseif($receiver->user_id){
                    $user = User::find($receiver->user_id);
                    $fcm_token=$user->cm_firebase_token;
                }
            }
        }else{
            if($request->receiver_type == 'vendor'){
                $receiver = UserInfo::where('vendor_id',$request->receiver_id)->first();
                $vendor = Vendor::find($request->receiver_id);

                if(!$receiver){
                    $receiver = new UserInfo();
                    $receiver->vendor_id = $vendor->id;
                    $receiver->f_name = $vendor?->restaurants[0]?->getRawOriginal('name');
                    $receiver->l_name = '';
                    $receiver->phone = $vendor->phone;
                    $receiver->email = $vendor->email;
                    $receiver->image = $vendor?->restaurants[0]?->logo;
                    $receiver->save();
                }
                $receiver_id = $receiver->id;
                $fcm_token=$vendor->firebase_token;
                $fcm_token_web="restaurant_panel_{$vendor?->restaurants[0]?->id}_message";
            }else if($request->receiver_type == 'customer'){
                $receiver = UserInfo::where('user_id',$request->receiver_id)->first();
                $user = User::find($request->receiver_id);
                // dd($user);

                if(!$receiver){
                    $receiver = new UserInfo();
                    $receiver->user_id = $user->id;
                    $receiver->f_name = $user->f_name;
                    $receiver->l_name = $user->l_name;
                    $receiver->phone = $user->phone;
                    $receiver->email = $user->email;
                    $receiver->image = $user->image;
                    $receiver->save();
                }
                $receiver_id = $receiver->id;
                $fcm_token=$user->cm_firebase_token;
            }
        }

        $conversation = Conversation::WhereConversation($sender->id,$receiver_id)->first();

        if(!$conversation){
            $conversation = new Conversation;
            $conversation->sender_id = $sender->id;
            $conversation->sender_type = 'delivery_man';
            $conversation->receiver_id = $receiver->id;
            $conversation->receiver_type = $request->receiver_type;
            $conversation->unread_message_count = 0;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();
            $conversation= Conversation::find($conversation->id);
        }


        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->message = $request->message;
        $message->file = $image_name?json_encode($image_name, JSON_UNESCAPED_SLASHES):null;
        try {
            if($message->save())
            $conversation->unread_message_count = $conversation->unread_message_count? $conversation->unread_message_count+1:1;
            $conversation->last_message_id=$message->id;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();
            {
                $data = [
                    'title' =>translate('messages.message'),
                    'description' =>translate('messages.message_description'),
                    'order_id' => '',
                    'image' => '',
                    'message' => json_encode($message) ,
                    'type'=> 'message',
                    'conversation_id'=> $conversation->id,
                    'sender_type'=> 'delivery_man'
                ];
                Helpers::send_push_notif_to_device($fcm_token, $data);
                if($fcm_token_web){
                    Helpers::send_push_notif_to_topic($data, $fcm_token_web, 'message');
                }
            }

        } catch (\Exception $e) {
            info($e);
        }

        $messages = Message::with('order')->where(['conversation_id' => $conversation->id])->latest()->paginate($limit, ['*'], 'page', $offset);

        $conv = Conversation::with('sender','receiver','last_message.order')->find($conversation->id);

        if($conv->sender_type == 'vendor' && $conversation->sender){
            $vd = Vendor::find($conv->sender->vendor_id);
            $order = Order::where('delivery_man_id',$dm->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->receiver_type == 'vendor' && $conversation->receiver){
            $vd = Vendor::find($conv->receiver->vendor_id);
            $order = Order::where('delivery_man_id',$dm->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->sender_type == 'customer' && $conversation->sender){
            $user = User::find($conv->sender->user_id);
            $order = Order::where('delivery_man_id',$dm->id)->where('user_id', $user->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }else if($conv->receiver_type == 'customer' && $conversation->receiver){
            $user = User::find($conv->receiver->user_id);
            $order = Order::where('delivery_man_id',$dm->id)->where('user_id', $user->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
        }
        else{
            $order=0;
        }


        $data =  [
            'total_size' => intval($messages->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'status' => ($order>0)?true:false,
            'message' => 'successfully sent!',
            'messages' => $messages->items(),
            'conversation' => $conv,
        ];
        return response()->json($data, 200);
    }

    public function dm_conversations(Request $request)
    {
        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $delivery_man = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $sender = UserInfo::where('deliveryman_id', $delivery_man->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->deliveryman_id = $delivery_man->id;
            $sender->f_name = $delivery_man->f_name;
            $sender->l_name = $delivery_man->l_name;
            $sender->phone = $delivery_man->phone;
            $sender->email = $delivery_man->email;
            $sender->image = $delivery_man->image;
            $sender->save();
        }


        $conversations = Conversation::with('sender','receiver','last_message.order')
            ->where(function ($query) use ($sender, $request) {
                $query->where('sender_id', $sender->id)
                    ->orWhere('receiver_id', $sender->id);
            })
            ->when(isset($request->type), function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('receiver_type', $request->type)
                            ->where('sender_type', 'delivery_man');
                    })->orWhere(function ($q) use ($request) {
                        $q->where('sender_type', $request->type)
                            ->where('receiver_type', 'delivery_man');
                    });
                });
            })
            ->orderBy('last_message_time', 'DESC')
            ->paginate($limit, ['*'], 'page', $offset);


        $data =  [
            'type'=>$request->type ?? null,
            'total_size' => intval($conversations->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'conversation' => $conversations->items()
        ];

        return response()->json($data, 200);
    }

    public function dm_search_conversations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $key = explode(' ', $request['name']);

        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $delivery_man = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $sender = UserInfo::where('deliveryman_id', $delivery_man->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->deliveryman_id = $delivery_man->id;
            $sender->f_name = $delivery_man->f_name;
            $sender->l_name = $delivery_man->l_name;
            $sender->phone = $delivery_man->phone;
            $sender->email = $delivery_man->email;
            $sender->image = $delivery_man->image;
            $sender->save();
        }

        $conversations = Conversation::with('sender','receiver','last_message.order')->WhereUser($sender->id)
            ->when(isset($request->type), function ($query) use ($request) {
                $query->where(function ($q) use ($request) {
                    $q->where(function ($q) use ($request) {
                        $q->where('receiver_type', $request->type)
                            ->where('sender_type', 'delivery_man');
                    })->orWhere(function ($q) use ($request) {
                        $q->where('sender_type', $request->type)
                            ->where('receiver_type', 'delivery_man');
                    });
                });
            })
            ->where(function($qu)use($key){
                    $qu->whereHas('sender',function($query)use($key){
                    foreach ($key as $value) {
                        $query->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%");
                    }
                })
                ->orWhereHas('receiver',function($query1)use($key){
                    foreach ($key as $value) {
                        $query1->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%");
                    }
                });
            });

        $conversations = $conversations->orderBy('last_message_time', 'DESC')->paginate($limit, ['*'], 'page', $offset);

        $data =  [
            'total_size' => intval($conversations->total()),
            'limit' => intval($limit),
            'offset' => intval($offset),
            'conversation' => $conversations->items()
        ];
        return response()->json($data, 200);
    }


    public function dm_messages(Request $request)
    {
        $limit = $request['limit']??10;
        $offset = $request['offset']??1;

        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();
        $delivery_man = UserInfo::where('deliveryman_id',$dm->id)->first();

        if(!$delivery_man){
            $delivery_man = new UserInfo();
            $delivery_man->deliveryman_id = $dm->id;
            $delivery_man->f_name = $dm->f_name;
            $delivery_man->l_name = $dm->l_name;
            $delivery_man->phone = $dm->phone;
            $delivery_man->email = $dm->email;
            $delivery_man->image = $dm->image;
            $delivery_man->save();
        }

        if($request->conversation_id){
            $conversation = Conversation::with(['sender','receiver','last_message'])->find($request->conversation_id);
        }else if($request->vendor_id){
            $vendor = UserInfo::where('vendor_id', $request->vendor_id)->first();
            if(!$vendor){
                $user = Vendor::find($request->vendor_id);
                $vendor = new UserInfo();
                $vendor->vendor_id = $user->id;
                $vendor->f_name = $user?->restaurants[0]?->name;
                $vendor->l_name = '';
                $vendor->phone = $user->phone;
                $vendor->email = $user->email;
                $vendor->image = $user->image;
                $vendor->save();
            }
            $conversation = Conversation::with(['sender','receiver','last_message'])->WhereConversation($delivery_man->id,$vendor->id)->first();

        }else if($request->user_id){
            $user = UserInfo::where('user_id', $request->user_id)->first();
            if(!$user){
                $customer = User::find($request->user_id);
                $user = new UserInfo();
                $user->user_id = $customer->id;
                $user->f_name = $customer->f_name;
                $user->l_name = $customer->l_name;
                $user->phone = $customer->phone;
                $user->email = $customer->email;
                $user->image = $customer->image;
                $user->save();
            }
            $conversation = Conversation::with(['sender','receiver','last_message'])->WhereConversation($delivery_man->id,$user->id)->first();
        }

        if($conversation){

            if($conversation->sender_id != $delivery_man->id && $conversation->receiver_id != $delivery_man->id){
                return response()->json(['errors' => [['code' => 'conversation', 'message' => translate('messages.not_found')]]], 403);
            }
            if($conversation->sender_type == 'vendor' && $conversation->sender){
                $vd = Vendor::find($conversation->sender->vendor_id);
                $order = Order::where('delivery_man_id',$dm->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->receiver_type == 'vendor' && $conversation->receiver){
                $vd = Vendor::find($conversation->receiver->vendor_id);
                $order = Order::where('delivery_man_id',$dm->id)->where('restaurant_id', $vd?->restaurants[0]?->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->sender_type == 'customer' && $conversation->sender){
                $user = User::find($conversation->sender->user_id);
                $order = Order::where('delivery_man_id',$dm->id)->where('user_id', $user->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }else if($conversation->receiver_type == 'customer' && $conversation->receiver){
                $user = User::find($conversation->receiver->user_id);
                $order = Order::where('delivery_man_id',$dm->id)->where('user_id', $user->id)->whereIn('order_status', ['pending','accepted','confirmed','processing','handover','picked_up'])->count();
            }
            else{
                $order=0;
            }


            $lastmessage = $conversation->last_message;
            if($lastmessage && $lastmessage->sender_id != $delivery_man->id ) {
                $conversation->unread_message_count = 0;
                $conversation->save();
            }

            Message::where(['conversation_id' => $conversation->id])->where('sender_id','!=',$delivery_man->id)->update(['is_seen' => 1]);
            $messages = Message::with('order')->where(['conversation_id' => $conversation->id])->latest()->paginate($limit, ['*'], 'page', $offset);
        }else{
            $messages =[];
            $order=0;
        }

        $data =  [
            'total_size' => $messages? intval($messages->total()):0,
            'limit' => intval($limit),
            'offset' => intval($offset),
            'status' => ($order>0)?true:false,
            'messages' => $messages? $messages->items():[],
            'conversation' => $conversation
        ];
        return response()->json($data, 200);
    }
}
