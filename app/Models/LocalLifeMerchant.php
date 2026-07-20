<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaContacts;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * 本地生活商家（后台录入）。前端商家列表/商家店铺页以本表为准。
 * 营业状态按商家所在地埃里温(Asia/Yerevan)时区判断（与全站时间口径一致）。
 */
class LocalLifeMerchant extends Model
{
    protected $fillable = [
        'name', 'category', 'logo', 'images', 'cover_image', 'wechat_qr', 'contacts',
        'rating', 'google_rating', 'google_rating_count', 'google_rating_url',
        'area', 'address', 'latitude', 'longitude',
        'open_days', 'open_time', 'close_time', 'hours_note',
        'intro', 'services', 'video_links', 'has_offer', 'offer_text',
        'is_sensitive', 'sort_order', 'status',
    ];

    protected $casts = [
        'images'        => 'array',
        'contacts'      => 'array',
        'open_days'     => 'array',
        'services'      => 'array',
        'video_links'   => 'array',
        'rating'        => 'float',
        'google_rating' => 'float',
        'latitude'      => 'float',
        'longitude'     => 'float',
        'has_offer'     => 'boolean',
        'is_sensitive'  => 'boolean',
        'status'        => 'boolean',
        'sort_order'    => 'integer',
    ];

    /** 联系意图埋点事件（A·近30天咨询 withCount 用；append-only 零主体标识） */
    public function contactEvents()
    {
        return $this->hasMany(LocalLifeContactEvent::class, 'merchant_id');
    }

    /** 当前是否营业中（按埃里温时区；无营业时间数据时返回 null=未知） */
    public function isOpenNow(): ?bool
    {
        if (empty($this->open_time) || empty($this->close_time)) {
            return null;
        }
        $now = Carbon::now('Asia/Yerevan');
        $dow = (int) $now->dayOfWeek; // 0=周日..6=周六
        $days = is_array($this->open_days) ? $this->open_days : [];
        if (!empty($days) && !in_array($dow, array_map('intval', $days), true)) {
            return false;
        }
        $cur   = $now->format('H:i');
        $open  = $this->open_time;
        $close = $this->close_time;
        if ($close > $open) {
            return $cur >= $open && $cur < $close;     // 当日时段
        }
        // 跨夜（如 20:00-02:00）
        return $cur >= $open || $cur < $close;
    }

    /** 今日营业时间文字（店铺页「营业中 周三：09:00-18:00」用） */
    public function todayHoursLabel(): string
    {
        if (empty($this->open_time) || empty($this->close_time)) {
            return $this->hours_note ?: '营业时间以商家为准';
        }
        $names = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
        $dow   = (int) Carbon::now('Asia/Yerevan')->dayOfWeek;
        return $names[$dow] . '：' . $this->open_time . '-' . $this->close_time;
    }

    /**
     * 结构化联系方式（前端可点 deep link 用）。
     * 返回规范化数组：[{method, value, label, href, copy}]
     *   - method: wechat|phone|whatsapp|telegram
     *   - href:   tel: / https://wa.me/<digits> / https://t.me/<user>；微信无 href（走复制+二维码）
     *   - copy:   微信=号码文本供前端复制；其它为 null
     * 老数据（contacts 空）返回 []，前端降级到「查看联系方式」。
     * L1-1：仅展示，不含任何支付/下单。
     */
    public function normalizedContacts(): array
    {
        // 2026-07-20：实现抽到 NezhaContacts::normalize() 与外卖挂牌店共用（业主拍板改后端而非前端另写一套）。
        // 对本页行为不变：32 用例对拍（含本表 9 家生产真实数据）仅 stdClass 元素一例分叉，
        // 而 'array' cast 走 json_decode(...,true) 不产出 stdClass，该分支到不了。详见 NezhaContacts 头注释。
        return NezhaContacts::normalize($this->contacts);
    }

    /* ───────────── 店内视频外链卡（档1·L1-1 纯信息墙·外跳不嵌入） ───────────── */

    /** 视频封面存储目录（与相册 / Helpers::upload 一致） */
    private const VIDEO_IMG_DIR = 'local-life-merchant';
    private const VIDEO_MAX = 6;

    /**
     * 平台 → 允许的 host 后缀（子域一律允许：host === 域名 或 以「.域名」结尾）。
     * v.douyin.com / iesdouyin.com 均为抖音；vm./vt.tiktok.com 均为 tiktok.com 子域。
     */
    private const VIDEO_DOMAINS = [
        'douyin'      => ['douyin.com', 'iesdouyin.com'],
        'xiaohongshu' => ['xiaohongshu.com', 'xhslink.com'],
        'tiktok'      => ['tiktok.com'],
        'instagram'   => ['instagram.com', 'instagr.am'],
    ];

    private const VIDEO_LABELS = [
        'douyin'      => '抖音',
        'xiaohongshu' => '小红书',
        'tiktok'      => 'TikTok',
        'instagram'   => 'Instagram',
    ];

    /**
     * 规范化店内视频外链（详情页视频卡用）。逐条过滤：
     *   ① platform ∈ 四平台白名单
     *   ② url = https + host 后缀过白名单（防 evil.com/douyin.com 路径伪装）
     *   ③ cover 非空且文件真实存在（悬空文件名守卫，同 merchantHeroPath 思路）
     * 产出 [{platform, platform_label(中文名), url, cover_url(全量URL), title?}]，上限截前 6。
     * 仅 merchantDetail API 在总闸开时调用；闸关/空 → 前端整卡不显。
     * L1-1：纯展示 + 外跳，无下单/预订/团购。
     */
    public function normalizedVideoLinks(): array
    {
        $raw = is_array($this->video_links) ? $this->video_links : [];
        $out = [];
        foreach ($raw as $v) {
            if (count($out) >= self::VIDEO_MAX) {
                break;
            }
            if (!is_array($v)) {
                continue;
            }
            $platform = strtolower(trim((string) ($v['platform'] ?? '')));
            $url      = trim((string) ($v['url'] ?? ''));
            $cover    = basename(trim((string) ($v['cover'] ?? '')));
            $title    = trim((string) ($v['title'] ?? ''));
            if (!isset(self::VIDEO_DOMAINS[$platform]) || $url === '' || $cover === '') {
                continue;
            }
            if (!$this->videoUrlAllowed($url, self::VIDEO_DOMAINS[$platform])) {
                continue;
            }
            // 封面文件存在守卫：悬空文件名 → 丢弃该条（禁「无封面显图标卡」回落）
            if (!Storage::disk('public')->exists(self::VIDEO_IMG_DIR . '/' . $cover)) {
                continue;
            }
            $coverUrl = Helpers::get_full_url(self::VIDEO_IMG_DIR, $cover, 'public');
            if (!$coverUrl) {
                continue;
            }
            $out[] = [
                'platform'       => $platform,
                'platform_label' => self::VIDEO_LABELS[$platform],
                'url'            => $url,
                'cover_url'      => $coverUrl,
                'title'          => $title !== '' ? $title : null,
            ];
        }
        return $out;
    }

    /** url 只认 https + host 后缀命中白名单（子域允许）。防路径/查询串伪装。 */
    private function videoUrlAllowed(string $url, array $allowed): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach ($allowed as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return true;
            }
        }
        return false;
    }
}
