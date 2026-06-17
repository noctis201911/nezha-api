<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use App\Traits\SerializesLocalDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    // 聊天消息时间序列化为裸埃里温墙钟，避免前端 moment 按浏览器时区偏移（见 trait）。
    use HasFactory, SerializesLocalDates;

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'is_seen' => 'integer'
    ];

    protected $appends = ['file_full_url'];

    public function sender()
    {
        return $this->belongsTo(UserInfo::class, 'sender_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function getFileFullUrlAttribute(){
        $images = [];
        $value = is_array($this->file)
            ? $this->file
            : ($this->file && is_string($this->file) && $this->isValidJson($this->file)
                ? json_decode($this->file, true)
                : []);
        if ($value){
            foreach ($value as $item){
                $item = is_array($item)?$item:(is_object($item) && get_class($item) == 'stdClass' ? json_decode(json_encode($item), true):['img' => $item, 'storage' => 'public']);
                $images[] = Helpers::get_full_url('conversation',$item['img'],$item['storage']);
            }
        }

        return $images;
    }

    private function isValidJson($string)
    {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}
