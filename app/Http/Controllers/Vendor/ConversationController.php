<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\UserInfo;
use App\Models\Message;
use App\Models\User;
use App\Models\DeliveryMan;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ConversationController extends Controller
{
    public function list(Request $request)
    {
        // 哪吒: 配送走 Yandex, 无 App 内骑手 —— 聊天只保留顾客, 强制 tab=customer
        // (同时修掉 ?tab=undefined 时顾客视图错显「未找到骑手」空态的问题)
        $tab = 'customer';
        $vendor = Helpers::get_vendor_data();
        $sender = UserInfo::where('vendor_id',$vendor->id)->first();


        if(!$sender){
            $sender = new UserInfo();
            $sender->vendor_id = $vendor->id;
            $sender->f_name = $vendor?->restaurants[0]?->getRawOriginal('name');
            $sender->l_name = '';
            $sender->phone = $vendor->phone;
            $sender->email = $vendor->email;
            $sender->image = $vendor?->restaurants[0]?->logo;
            $sender->save();
        }


        $admin_conversation = Conversation::with(['sender','receiver', 'last_message'])->WhereUser($sender?->id)->where('receiver_type', 'admin')->first();
        if(!$admin_conversation){
            $admin_conversation = new Conversation;
            $admin_conversation->sender_id = $sender->id;
            $admin_conversation->sender_type = 'vendor';
            $admin_conversation->receiver_id = 0;
            $admin_conversation->receiver_type = 'admin';
            $admin_conversation->last_message_time = Carbon::now()->toDateTimeString();
            $admin_conversation->save();
            $admin_conversation= Conversation::find($admin_conversation->id);
        }



        if($sender){
            $conversations = Conversation::with(['sender.user','receiver.user', 'last_message'])->WhereUser($sender->id)->WhereUserType($tab);
            if($request->query('key')) {
                $key = explode(' ', $request->get('key'));
                $conversations = $conversations->where(function($qu)use($key){
                    $qu->whereHas('sender',function($query)use($key){
                        foreach ($key as $value) {
                            $query->where('f_name', 'like', "%{$value}%")
                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhereHas('user', function($uq) use ($value){ $uq->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%"); });
                        }
                    })
                    ->orWhereHas('receiver',function($query1)use($key){
                        foreach ($key as $value) {
                            $query1->where('f_name', 'like', "%{$value}%")
                            ->orWhere('l_name', 'like', "%{$value}%")
                            ->orWhere('phone', 'like', "%{$value}%")
                            ->orWhereHas('user', function($uq) use ($value){ $uq->where('f_name', 'like', "%{$value}%")->orWhere('l_name', 'like', "%{$value}%"); });
                        }
                    });
                });
            }
            $conversations = $conversations->where(function($query) {
                $query->where('receiver_type', '!=', 'admin');
            });
            $conversations = $conversations->orderBy('last_message_time', 'DESC')
            ->latest()
            ->paginate(8);


        }else{
            // $admin_conversation = null;
            $conversations = [];
        }

            $admin = Admin::where('role_id', 1)->first();

        if ($request->ajax()) {
            $view = view('vendor-views.messages.data',compact('conversations','admin_conversation','admin','tab'))->render();
            $view2 = view('vendor-views.messages.admin_data',compact('conversations','admin_conversation','admin','tab'))->render();
            return response()->json(['html'=>$view,'admin_html'=>$view2]);
        }

        return view('vendor-views.messages.index', compact('conversations','admin_conversation','admin','tab'));
    }

    public function view($conversation_id,$user_id)
    {
        $conversation = Conversation::find($conversation_id);
        // 哪吒[IDOR修复 2026-06-25]: 校验会话归属当前商家——否则商家可枚举 conversation_id 读他人私聊
        // (顾客↔平台客服 / 别家商家↔顾客)并把对方消息篡改为已读。归属=本商家 UserInfo id 出现在 sender_id 或 receiver_id。
        $me = UserInfo::where('vendor_id', Helpers::get_vendor_data()->id)->first();
        if (!$conversation || !$me || ($conversation->sender_id != $me->id && $conversation->receiver_id != $me->id)) {
            abort(404);
        }
        $lastmessage = $conversation->last_message;
        if($lastmessage && $lastmessage->sender_id == $user_id ) {
            $conversation->unread_message_count = 0;
            $conversation->save();
        }
        Message::where(['conversation_id' => $conversation->id])->where('sender_id',$user_id)->update(['is_seen' => 1]);
        $convs = Message::with('order')->where(['conversation_id' => $conversation_id])->get();
        $conversation= Conversation::find($conversation_id);
        $receiver = $conversation->receiver;
        $sender = $conversation->sender;
        $vendor = Helpers::get_vendor_data();
        $vendor = UserInfo::where('vendor_id',$vendor->id)->first();

        if($conversation->receiver_id == 0){
            $user = Admin::where('role_id', 1)->first();
            $user_type = 'admin';
        }elseif($receiver->user_id){
            $user = User::find($receiver->user_id);
            $user_type = 'user';
        }elseif($receiver->deliveryman_id){
            $user = DeliveryMan::find($receiver->deliveryman_id);
            $user_type = 'delivery_man';
        }elseif($sender->user_id){
            $user = User::find($sender->user_id);
            $user_type = 'user';
        }else{
            $user = DeliveryMan::find($sender->deliveryman_id);
            $user_type = 'delivery_man';
        }

        return response()->json([
            'view' => view('vendor-views.messages.partials._conversations', compact('convs', 'user', 'receiver','sender','user_type','vendor'))->render()
        ]);
    }

    // 哪吒: 商家面板聊天「新消息轮询」轻量状态——返回未读总数 + 最新一条「非本人发」消息 id（作为响铃触发信号）。
    // 严格按当前商家 UserInfo 派生，无越权；只读、极轻量（两条聚合查询）。
    public function live_status(Request $request)
    {
        $vendor = Helpers::get_vendor_data();
        $me = UserInfo::where('vendor_id', $vendor->id)->first();
        if (!$me) {
            return response()->json(['total_unread' => 0, 'latest_incoming_id' => 0]);
        }
        $convIds = Conversation::whereUser($me->id)->pluck('id');
        if ($convIds->isEmpty()) {
            return response()->json(['total_unread' => 0, 'latest_incoming_id' => 0]);
        }
        // 哪吒: 拆分顾客会话与客服(admin)会话的最新「非本人发」消息 id，
        // 让商家面板对顾客消息 / 客服消息播放不同音色提示音（前端按字段分别响铃）。
        $adminConvIds = Conversation::whereUser($me->id)
            ->where(function ($q) {
                $q->where('receiver_type', 'admin')->orWhere('sender_type', 'admin');
            })->pluck('id');
        $customerConvIds = $convIds->diff($adminConvIds)->values();

        // 取每个渠道最新「非本人发」消息(含所属会话 id，供前端判断是否正开着该会话以免重复打扰)
        $latestCustomerMsg = $customerConvIds->isEmpty() ? null : Message::whereIn('conversation_id', $customerConvIds)
            ->where('sender_id', '!=', $me->id)->orderByDesc('id')->first(['id', 'conversation_id']);
        $latestAdminMsg = $adminConvIds->isEmpty() ? null : Message::whereIn('conversation_id', $adminConvIds)
            ->where('sender_id', '!=', $me->id)->orderByDesc('id')->first(['id', 'conversation_id']);
        $latestCustomerIncomingId = $latestCustomerMsg ? (int) $latestCustomerMsg->id : 0;
        $latestAdminIncomingId = $latestAdminMsg ? (int) $latestAdminMsg->id : 0;
        $latestIncomingId = max($latestCustomerIncomingId, $latestAdminIncomingId);
        $totalUnread = (int) Conversation::whereUser($me->id)
            ->whereHas('last_message', function ($q) use ($me) {
                $q->where('sender_id', '!=', $me->id);
            })->sum('unread_message_count');
        return response()->json([
            'total_unread' => $totalUnread,
            'latest_incoming_id' => $latestIncomingId,
            'latest_customer_incoming_id' => $latestCustomerIncomingId,
            'latest_admin_incoming_id' => $latestAdminIncomingId,
            'latest_customer_incoming_conv_id' => $latestCustomerMsg ? (int) $latestCustomerMsg->conversation_id : 0,
            'latest_admin_incoming_conv_id' => $latestAdminMsg ? (int) $latestAdminMsg->conversation_id : 0,
        ]);
    }

    public function store(Request $request, $user_id, $user_type)
    {
        if ($request->has('images')) {

            $validator = Validator::make($request->all(), [
                'images.*' => 'max:5120',
            ],[
                'images.*.max' => translate('Max File Upload limit is 5mb')
            ]);
            if ($validator->fails()) {
                $validator->getMessageBag()->add('images', translate('Max File Upload limit is 5mb'));
                return response()->json(['errors' => Helpers::error_processor($validator)]);
            }

            $image_name=[];
            foreach($request->images as $key=>$img)
            {
                $name = Helpers::upload(dir:'conversation/', format:$img->getClientOriginalExtension(),image: $img);
                array_push($image_name,['img'=>$name, 'storage'=> Helpers::getDisk()]);
            }
        } else {
            $image_name = null;

            $validator = Validator::make($request->all(), [
                'reply' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => Helpers::error_processor($validator)]);
            }
        }

        $error_message = null;
        $file_count=0;

        $vendor = Helpers::get_vendor_data();
        $sender = UserInfo::where('vendor_id',$vendor->id)->first();
        if(!$sender){
            $sender = new UserInfo();
            $sender->vendor_id = $vendor->id;
            $sender->f_name = $vendor?->restaurants[0]?->getRawOriginal('name');
            $sender->l_name = '';
            $sender->phone = $vendor->phone;
            $sender->email = $vendor->email;
            $sender->image = $vendor?->restaurants[0]?->logo;
            $sender->save();
        }

        if($user_type == 'admin'){
            $user = Admin::where('role_id', 1)->first();
            $receiver_id = 0;
            $receiver = UserInfo::where('admin_id', $user->id)->first();
        } else if($user_type == 'user'){
            $user = User::find($user_id);
            $fcm_token=$user->cm_firebase_token;
            $receiver = UserInfo::where('user_id', $user->id)->first();
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

        }elseif($user_type == 'delivery_man'){
            $user = DeliveryMan::find($user_id);
            $fcm_token=$user->fcm_token;
            $receiver = UserInfo::where('deliveryman_id', $user->id)->first();
            if(!$receiver){
                $receiver = new UserInfo();
                $receiver->deliveryman_id = $user->id;
                $receiver->f_name = $user->f_name;
                $receiver->l_name = $user->l_name;
                $receiver->phone = $user->phone;
                $receiver->email = $user->email;
                $receiver->image = $user->image;
                $receiver->save();
            }
            $receiver_id = $receiver->id;
        }



        $conversation = Conversation::WhereConversation($sender->id,$receiver_id)->first();


        if(!$conversation){
            $conversation = new Conversation;
            $conversation->sender_id = $sender->id;
            $conversation->sender_type = 'vendor';
            $conversation->receiver_id = $receiver_id;
            $conversation->receiver_type = $user_type;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();

            $conversation= Conversation::find($conversation->id);
        }

        $message = new Message();
        $message->conversation_id = $conversation->id;
        $message->sender_id = $sender->id;
        $message->message = $request->reply;
        if($image_name && count($image_name)>0){
            $message->file = json_encode($image_name, JSON_UNESCAPED_SLASHES);
            $file_count= count($image_name);
        }
        try {
            if($message->save())
            $conversation->unread_message_count = $conversation->unread_message_count? $conversation->unread_message_count+1:1;
            $conversation->last_message_id=$message->id;
            $conversation->last_message_time = Carbon::now()->toDateTimeString();
            $conversation->save();
            {
                if($user_type == 'admin' || $receiver_id == 0){
                    $data = [
                        'title' =>translate('messages.message'),
                        'description' => $message?->message ??  $file_count.' '.translate('messages.Attachments'),
                        'order_id' => '',
                        'image' => '',
                        'message' => json_encode($message) ,
                        'type'=> 'message'
                    ];
                    Helpers::send_push_notif_to_topic($data,'admin_message','message');
                }else {
                    $data = [
                        'title' =>translate('messages.message'),
                        'description' => $message?->message ??  $file_count.' '.translate('messages.Attachments'),
                        'order_id' => '',
                        'image' => '',
                        'message' => $message,
                        'type'=> 'message',
                        'conversation_id'=> $conversation->id,
                        'sender_type'=> 'vendor'
                    ];
                    // 哪吒: 顾客「客服与商家消息」推送偏好闸(仅当收信方是顾客时拦截, 骑手不受影响)
                    if ($user_type !== 'user' || Helpers::customerWantsPush($user, 'chat')) {
                        Helpers::send_push_notif_to_device($fcm_token, $data);
                    }
                }
            }

        } catch (\Exception $e) {
            info($e->getMessage());
            $error_message = $e->getMessage();
        }
        if($request->reply || $file_count > 0){
            $response_message=  translate('Message sent') ;
        }
        if( $request->has('images') && $file_count <= 0 ){
            $error_message =   $error_message  == null ? translate('messages.Unable_to_sent_Attachments') : $error_message ;
        }


        $vendor = UserInfo::where('vendor_id',$vendor->id)->first();
        $convs = Message::with('order')->where(['conversation_id' => $conversation->id])->get();
        return response()->json([
            'view' => view('vendor-views.messages.partials._conversations', compact('convs', 'user', 'receiver','user_type','vendor'))->render(),
            'error_message' => $error_message ?? null,
            'response_message' => $response_message ?? null
        ]);
    }
}
