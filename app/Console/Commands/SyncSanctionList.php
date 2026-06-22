<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\CentralLogics\Helpers;
use App\CentralLogics\NezhaSanctionScreen;
use App\Models\NezhaSanctionAddress;
use App\Models\NezhaSanctionName;
use App\CentralLogics\NezhaKycScreen;
use Carbon\Carbon;

/**
 * 哪吒 制裁筛查② — 从 OFAC 公开 SDN 名单拉「数字货币地址」入本地表 (L1-6 数据源).
 *
 * 背景: 平台 USDT 收款通道需对付款来源链上地址比对 OFAC SDN 制裁名单, 命中即拒收。
 * OFAC 在 SDN 名单中以 idType="Digital Currency Address - XXX" 形式公布受制裁地址。
 * 本命令拉取并解析这些地址, upsert 入 nezha_sanction_addresses, 供 NezhaSanctionScreen 实时比对。
 *
 * 合规等级: L1-6 数据维护。地址按格式规范化(EVM 小写 / Tron base58 原样)入库。
 *
 * 护栏(失败安全, 任一触发则【不动现有名单、记录告警状态】, 命令仍成功退出避免反复告警):
 *   1) 取数失败 / 内容为空
 *   2) 解析出 0 条数字货币地址(疑似源结构变更或坏数据) → 绝不清空既有名单(宁可沿用旧名单)
 * 仅在成功解析 ≥1 条时 upsert(新增/更新, 不删除既有 —— 避免坏数据致漏筛; 退市极少, 必要时人工清理)。
 * 每次结果(ok/skipped/dry-run)写入 business_settings.nezha_sanction_last_sync, 后台展示。
 *
 * 选项:
 *   --dry-run   只报告将写入多少条, 不落库
 *   --file=PATH 从本地文件解析(离线导入/测试用), 不走网络
 */
class SyncSanctionList extends Command
{
    protected $signature = 'nezha:sync-sanction-list {--dry-run : 只报告解析结果, 不落库} {--file= : 从本地文件解析(测试/离线导入), 不走网络}';

    protected $description = '哪吒: 从 OFAC 公开 SDN 名单拉数字货币地址入库(制裁筛查 L1-6), 带护栏(取数/解析失败则保留旧名单)。';

    const SOURCE_KEY = 'nezha_sanction_source_url';
    const STATUS_KEY = 'nezha_sanction_last_sync';

    private string $lastError = '';

    public function handle()
    {
        $dry  = (bool) $this->option('dry-run');
        $file = (string) ($this->option('file') ?? '');

        try {
            // 1) 取数(本地文件 或 网络)
            if ($file !== '') {
                if (!is_file($file) || !is_readable($file)) {
                    return $this->bail("本地文件不可读: {$file}");
                }
                $body = (string) file_get_contents($file);
                $sourceLabel = 'file:' . basename($file);
            } else {
                $url  = (string) (DB::table('business_settings')->where('key', self::SOURCE_KEY)->value('value')
                        ?? 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/SDN.XML');
                $body = $this->fetch($url);
                if ($body === null) {
                    return $this->bail('取数失败: ' . $this->lastError);
                }
                $sourceLabel = $url;
            }
            if (trim($body) === '') {
                return $this->bail('取数内容为空');
            }

            // 2) 解析数字货币地址
            $addresses = $this->parseDigitalCurrencyAddresses($body);
            if (empty($addresses)) {
                // 0 条 → 疑似源结构变更/坏数据; 绝不清空既有名单。
                return $this->bail('解析出 0 条数字货币地址(疑似源结构变更), 保留既有名单不动。');
            }

            // 3) 规范化 + 去重
            $rows = [];
            foreach ($addresses as $a) {
                $raw  = trim($a['address'] ?? '');
                if ($raw === '') continue;
                $kind = NezhaSanctionScreen::kind($raw);
                $norm = NezhaSanctionScreen::normalize($raw, $kind);
                $key  = $kind . '|' . $norm;
                $rows[$key] = [
                    'addr_kind'     => $kind,
                    'address'       => $norm,
                    'source'        => 'OFAC_SDN',
                    'sdn_uid'       => $a['sdn_uid'] ?? null,
                    'currency_type' => $a['currency_type'] ?? null,
                ];
            }
            $total   = count($rows);
            $evm     = count(array_filter($rows, fn ($r) => $r['addr_kind'] === 'evm'));
            $tron    = count(array_filter($rows, fn ($r) => $r['addr_kind'] === 'tron'));
            $other   = $total - $evm - $tron;
            $summary = "解析 {$total} 条 (EVM {$evm} / Tron {$tron} / 其它 {$other}), 源={$sourceLabel}";

            if ($dry) {
                $this->info('[DRY-RUN] ' . $summary . ' — 未落库');
                $nameRes = $this->syncNames($body, true);
                $this->info('[DRY-RUN] 人名解析 ' . $nameRes['parsed'] . ' 条 — 未落库');
                $this->recordStatus('dry-run', $summary, $total);
                return self::SUCCESS;
            }

            // 4) upsert(新增/更新, 不删除既有) + 标记 last_seen_sync
            $now      = now();
            $inserted = 0;
            $updated  = 0;
            foreach (array_values($rows) as $r) {
                $existing = NezhaSanctionAddress::where('addr_kind', $r['addr_kind'])
                    ->where('address', $r['address'])
                    ->where('source', $r['source'])
                    ->first();
                if ($existing) {
                    $existing->sdn_uid        = $r['sdn_uid'] ?? $existing->sdn_uid;
                    $existing->currency_type  = $r['currency_type'] ?? $existing->currency_type;
                    $existing->last_seen_sync = $now;
                    $existing->save();
                    $updated++;
                } else {
                    NezhaSanctionAddress::create($r + [
                        'added_at'       => $now,
                        'last_seen_sync' => $now,
                    ]);
                    $inserted++;
                }
            }

            // —— 阶段1: 同步 OFAC SDN 人名/实体名到 nezha_sanction_names ——
            $nameRes = $this->syncNames($body, false);
            $msg = "{$summary}; 地址 新增 {$inserted}/更新 {$updated}; 人名 解析 {$nameRes['parsed']} 新增 {$nameRes['inserted']}/更新 {$nameRes['updated']}";
            $this->info('已同步: ' . $msg);
            Log::info('[nezha-sanction] 同步成功: ' . $msg);
            $this->recordStatus('ok', $msg, $total);
            return self::SUCCESS;
        } catch (\Throwable $e) {
            return $this->bail('异常: ' . $e->getMessage());
        }
    }

    /**
     * 解析 OFAC SDN XML 中的数字货币地址.
     * 结构: <sdnEntry><uid>..</uid><idList><id><idType>Digital Currency Address - ETH</idType><idNumber>0x..</idNumber></id></idList></sdnEntry>
     * 先剥默认命名空间(端点不同 ns URI 可能不同), 再用 SimpleXML 属性访问, 最稳。
     * @return array<int,array{address:string,currency_type:?string,sdn_uid:?string}>
     */
    private function parseDigitalCurrencyAddresses(string $body): array
    {
        $out = [];

        // 剥掉默认命名空间, 规避 ns URI 差异导致的 SimpleXML 取不到子节点
        $clean = preg_replace('/\sxmlns="[^"]*"/', '', $body, 1);

        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($clean);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            $this->lastError = 'XML 解析失败';
            return [];
        }

        foreach ($xml->sdnEntry as $entry) {
            $uid = (string) ($entry->uid ?? '');
            if (!isset($entry->idList) || !isset($entry->idList->id)) {
                continue;
            }
            foreach ($entry->idList->id as $id) {
                $idType = (string) ($id->idType ?? '');
                if (stripos($idType, 'Digital Currency Address') === false) {
                    continue;
                }
                $number = trim((string) ($id->idNumber ?? ''));
                if ($number === '') {
                    continue;
                }
                // 币种后缀: "Digital Currency Address - ETH" → ETH
                $ccy = null;
                if (preg_match('/Digital Currency Address\s*-\s*(\S+)/i', $idType, $mm)) {
                    $ccy = strtoupper($mm[1]);
                }
                $out[] = [
                    'address'       => $number,
                    'currency_type' => $ccy,
                    'sdn_uid'       => $uid !== '' ? $uid : null,
                ];
            }
        }
        return $out;
    }

    /**
     * 解析 OFAC SDN 人名/实体名 + 别名(aka), upsert 入 nezha_sanction_names.
     * 失败安全: 解析 0 条则不动既有人名名单(与地址同准则)。dry=true 只解析不落库。
     * @return array{parsed:int,inserted:int,updated:int}
     */
    private function syncNames(string $body, bool $dry): array
    {
        $names = $this->parseIndividualNames($body);

        // 同一 run 内去重(name_norm|type|uid)
        $uniq = [];
        foreach ($names as $n) {
            $uniq[$n['name_norm'] . '|' . $n['name_type'] . '|' . $n['sdn_uid']] = $n;
        }
        $names = array_values($uniq);

        $res = ['parsed' => count($names), 'inserted' => 0, 'updated' => 0];
        if (empty($names)) {
            Log::warning('[nezha-sanction] 人名解析 0 条(疑似源结构变更), 保留既有人名名单不动。');
            return $res;
        }
        if ($dry) {
            return $res;
        }

        $now = now();
        foreach ($names as $n) {
            $existing = NezhaSanctionName::where('name_norm', $n['name_norm'])
                ->where('name_type', $n['name_type'])
                ->where('sdn_uid', $n['sdn_uid'])
                ->where('source', 'OFAC_SDN')
                ->first();
            if ($existing) {
                $existing->name_raw       = $n['name_raw'] ?: $existing->name_raw;
                $existing->programs       = $n['programs'] ?: $existing->programs;
                $existing->last_seen_sync = $now;
                $existing->save();
                $res['updated']++;
            } else {
                NezhaSanctionName::create($n + [
                    'source'         => 'OFAC_SDN',
                    'added_at'       => $now,
                    'last_seen_sync' => $now,
                ]);
                $res['inserted']++;
            }
        }
        Log::info('[nezha-sanction] 人名同步: 解析 ' . $res['parsed'] . ' 新增 ' . $res['inserted'] . ' 更新 ' . $res['updated']);
        return $res;
    }

    /**
     * 解析 SDN XML 的人名/实体名 + 别名.
     * 结构: <sdnEntry><uid/><firstName/><lastName/><sdnType>Individual|Entity</sdnType>
     *        <programList><program/></programList>
     *        <akaList><aka><type>a.k.a.</type><lastName/><firstName/></aka></akaList></sdnEntry>
     * 实体(Entity)通常只有 lastName 存全名; 个人(Individual)有 first+last。
     * @return array<int,array{name_norm:string,name_raw:string,name_type:string,sdn_uid:string,programs:string}>
     */
    private function parseIndividualNames(string $body): array
    {
        $out = [];
        $clean = preg_replace('/\sxmlns="[^"]*"/', '', $body, 1);
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($clean);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return [];
        }

        foreach ($xml->sdnEntry as $entry) {
            $uid  = trim((string) ($entry->uid ?? ''));
            $type = strtolower(trim((string) ($entry->sdnType ?? '')));
            $ntype = $type === 'individual' ? 'individual' : ($type === 'entity' ? 'entity' : ($type !== '' ? 'other' : 'other'));

            $progs = [];
            if (isset($entry->programList) && isset($entry->programList->program)) {
                foreach ($entry->programList->program as $p) {
                    $pp = trim((string) $p);
                    if ($pp !== '') $progs[] = $pp;
                }
            }
            $programs = implode(',', array_unique($progs));

            // 主名
            $this->pushName($out, trim((string) ($entry->firstName ?? '')), trim((string) ($entry->lastName ?? '')), $ntype, $uid, $programs);

            // 别名 aka
            if (isset($entry->akaList) && isset($entry->akaList->aka)) {
                foreach ($entry->akaList->aka as $aka) {
                    $this->pushName($out, trim((string) ($aka->firstName ?? '')), trim((string) ($aka->lastName ?? '')), $ntype, $uid, $programs);
                }
            }
        }
        return $out;
    }

    /** 组装一条人名记录(规范化 + 截断), 空名跳过。 */
    private function pushName(array &$out, string $first, string $last, string $ntype, string $uid, string $programs): void
    {
        $raw = preg_replace('/\s+/', ' ', trim($first . ' ' . $last));
        if ($raw === '') return;
        $norm = NezhaKycScreen::normalize_name($raw);
        if ($norm === '') return;
        $out[] = [
            'name_norm' => mb_substr($norm, 0, 191),
            'name_raw'  => mb_substr($raw, 0, 250),
            'name_type' => $ntype,
            'sdn_uid'   => $uid !== '' ? $uid : '',
            'programs'  => mb_substr($programs, 0, 250),
        ];
    }
    /** 护栏触发: 不动名单, 记录告警状态, 命令以成功退出(避免调度器反复报错告警)。 */
    private function bail(string $reason)
    {
        $this->warn('[nezha-sanction] 跳过同步: ' . $reason);
        Log::warning('[nezha-sanction] 跳过同步: ' . $reason);
        $this->recordStatus('skipped', $reason, null);
        return self::SUCCESS;
    }

    /** 把最近一次同步结果写进 business settings, 供后台「风控设置」页展示。 */
    private function recordStatus(string $status, string $detail, $count)
    {
        $payload = [
            'at'     => Carbon::now()->toDateTimeString(),
            'status' => $status,        // ok / skipped / dry-run
            'detail' => $detail,
            'count'  => $count,
            'total'  => (int) NezhaSanctionAddress::count(),
        ];
        Helpers::businessUpdateOrInsert(['key' => self::STATUS_KEY], ['value' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
    }

    /** PHP 原生 curl 取文本(不依赖 guzzle)。失败返回 null 并填 lastError。 */
    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'nezha-sanction-sync/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code !== 200) {
            $this->lastError = $err !== '' ? $err : ('HTTP ' . $code);
            return null;
        }
        return (string) $body;
    }
}
