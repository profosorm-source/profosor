<?php

namespace App\Controllers\Admin;

class CacheAdminController extends BaseAdminController
{
    private \App\Models\Setting $settingModel;

    public function __construct(\App\Models\Setting $settingModel)
    {
        parent::__construct();
        $this->settingModel = $settingModel;
    }

    public function index(): void
    {
        $cache  = \Core\Cache::getInstance();
        $driver = $cache->driver();

        if ($driver === 'redis') {
            $stats = $this->redisStats($cache);
        } else {
            $stats = $this->fileStats();
        }

        $stats['driver'] = $driver;

        view('admin/cache/index', [
            'title' => 'مدیریت Cache',
            'stats' => $stats,
        ]);
    }

    public function clear(): void
    {
        $body    = $this->request->body();
        $type    = $body['type'] ?? 'all';
        $cache   = \Core\Cache::getInstance();
        $cleared = 0;

        if ($type === 'settings') {
            $cache->forget('system:settings');
            // سازگاری با SettingService
            $legacyFile = BASE_PATH . '/storage/cache/system_settings.php';
            if (file_exists($legacyFile)) {
                @unlink($legacyFile);
            }
            $cleared = 1;
        } elseif ($type === 'kpi') {
            $cache->forget('kpi:dashboard:summary');
            $cache->forget('kpi:weekly_report');
            $cleared = 2;
        } elseif ($type === 'tags') {
            $tag  = $body['tag'] ?? '';
            if ($tag !== '') {
                $cache->tags([$tag])->flush();
                $cleared = "tag:{$tag}";
            }
        } else {
            $cache->flush();
            $cleared = 'همه';
        }

        $this->response->json(['success' => true, 'message' => "Cache پاک شد ({$cleared} آیتم)"]);
    }

    public function forget(): void
    {
        $body = $this->request->body();
        $key  = $body['key'] ?? '';
        if ($key !== '') {
            \Core\Cache::getInstance()->forget($key);
        }
        $this->response->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────
    //  آمار Redis
    // ─────────────────────────────────────────────────

    private function redisStats(\Core\Cache $cache): array
    {
        $redis  = $cache->redis();
        $prefix = env('REDIS_PREFIX', 'chortke') . ':';

        try {
            $info   = $redis->info();
            // استفاده از SCAN به‌جای KEYS برای جلوگیری از blocking در مقیاس بزرگ
            $keys = [];
            $cursor = '0';
            do {
                [$cursor, $batch] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 100]);
                $keys = array_merge($keys, $batch);
            } while ($cursor !== '0');
            $sample = [];

            foreach (array_slice($keys, 0, 50) as $k) {
                $ttl    = $redis->ttl($k);
                $sample[] = (object)[
                    'key'       => str_replace($prefix, '', $k),
                    'ttl'       => $ttl,
                    'expire_at' => $ttl > 0 ? time() + $ttl : 0,
                    'type'      => $redis->type($k),
                ];
            }

            return [
                'total_keys'       => count($keys),
                'used_memory'      => $info['used_memory_human'] ?? '—',
                'connected_clients'=> $info['connected_clients'] ?? '—',
                'uptime_days'      => isset($info['uptime_in_seconds'])
                    ? round($info['uptime_in_seconds'] / 86400, 1)
                    : '—',
                'hit_rate'         => $this->calcHitRate($info),
                'keys'             => $sample,
                // فایل‌ها معنا ندارند در Redis
                'total_files'      => 0,
                'valid_files'      => 0,
                'expired_files'    => 0,
                'total_size_kb'    => 0,
            ];
        } catch (\Throwable $e) {
    $this->logger->error('feature_flag.keys.fetch.failed', [
        'channel' => 'feature_flag',
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    return ['error' => 'internal_error', 'keys' => []];
}
    }

    private function calcHitRate(array $info): string
    {
        $hits   = (int) ($info['keyspace_hits']   ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total  = $hits + $misses;
        if ($total === 0) {
            return '—';
        }
        return round($hits / $total * 100, 1) . '%';
    }

    // ─────────────────────────────────────────────────
    //  آمار فایل (حالت قدیمی)
    // ─────────────────────────────────────────────────

    private function fileStats(): array
{
    $cacheDir   = BASE_PATH . '/storage/cache/app/';
    $files      = glob($cacheDir . '*.cache') ?: [];
    $totalFiles = count($files);
    $validFiles = $expiredFiles = $totalBytes = 0;
    $keys       = [];

    foreach ($files as $file) {
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            continue;
        }

        // امن‌تر از unserialize خام
        try {
    $data = unserialize($raw, ['allowed_classes' => false]);
} catch (\Throwable $e) {
    $data = null;
}

        if (!is_array($data)) {
            continue;
        }

        $sz = filesize($file);
        if ($sz !== false) {
            $totalBytes += $sz;
        }

        $expireAt = (int)($data['expire_at'] ?? 0);

        if ($expireAt > 0 && $expireAt < time()) {
            $expiredFiles++;
        } else {
            $validFiles++;
        }

        $keys[] = (object)[
            'key'       => basename($file, '.cache'),
            'expire_at' => $expireAt,
            'ttl'       => $expireAt > 0 ? max(0, $expireAt - time()) : 0,
            'type'      => 'string',
        ];
    }

    return [
        'total_files'   => $totalFiles,
        'valid_files'   => $validFiles,
        'expired_files' => $expiredFiles,
        'total_size_kb' => round($totalBytes / 1024, 1),
        'total_keys'    => $validFiles,
        'keys'          => array_slice($keys, 0, 50),
    ];
}
}