<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use Core\Logger;


class AuditTrail
{
    private Database $db;
    private Logger $logger;
    

    public function __construct(Database $db, Logger $logger)
{
    $this->db = $db;
    $this->logger = $logger;
}

    public function record(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            $safeEvent = $this->sanitizeEvent($event);
            $safeContext = $this->sanitizeContext($context);

            $this->db->query(
                "INSERT INTO audit_trail
                (event, user_id, actor_id, context, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $safeEvent,
                    $userId,
                    $actorId ?? $this->currentUserId(),
                    json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $this->clientIp(),
                    $this->userAgent(),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.record.failed', [
                'channel' => 'audit_trail',
                'event' => $event,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return false;
        }
    }

    public function diff(
        string $event,
        ?int $userId,
        array $before,
        array $after,
        array $ignore = ['updated_at', 'created_at', 'password', 'remember_token']
    ): bool {
        $changes = [];

        foreach ($after as $key => $newVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $oldVal = $before[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$key] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        if (empty($changes)) {
            return true;
        }

        return $this->record($event, $userId, ['changes' => $changes]);
    }

public function archiveOlderThan(int $days = 30, int $chunkSize = 2000): array
{
    try {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $archiveDir = dirname(__DIR__, 2) . '/storage/audit-archives';

        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0755, true);
        }

        $stamp = date('Ymd_His');
        $jsonlFile = $archiveDir . "/audit_{$stamp}.jsonl";
        $gzFile = $jsonlFile . '.gz';

        $fp = fopen($jsonlFile, 'ab');
        if (!$fp) {
            throw new \RuntimeException('Cannot create archive file');
        }

        $total = 0;
        $lastId = 0;

        while (true) {
            $rows = $this->db->fetchAll(
                "SELECT * FROM audit_trail
                 WHERE created_at < ? AND id > ?
                 ORDER BY id ASC
                 LIMIT ?",
                [$cutoff, $lastId, $chunkSize]
            );

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                fwrite($fp, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                $total++;
                $lastId = (int)($row['id'] ?? $lastId);
            }
        }

        fclose($fp);

        if ($total === 0) {
            @unlink($jsonlFile);
            return [
                'archived' => 0,
                'deleted' => 0,
                'file' => null,
                'cutoff' => $cutoff,
            ];
        }

        $in = fopen($jsonlFile, 'rb');
if (!$in) {
    throw new \RuntimeException('Cannot open archive temp file');
}

$out = gzopen($gzFile, 'wb9');
if (!$out) {
    fclose($in);
    throw new \RuntimeException('Cannot create gzip archive');
}

while (!feof($in)) {
    $chunk = fread($in, 8192);
    if ($chunk === false) {
        gzclose($out);
        fclose($in);
        throw new \RuntimeException('Cannot read archive temp chunk');
    }
    gzwrite($out, $chunk);
}

gzclose($out);
fclose($in);
@unlink($jsonlFile);

        if (!file_exists($gzFile) || filesize($gzFile) === 0) {
            throw new \RuntimeException('Archive gzip file is invalid');
        }

        $deleted = 0;
do {
    $delStmt = $this->db->query(
        "DELETE FROM audit_trail
         WHERE created_at < ?
         LIMIT 5000",
        [$cutoff]
    );
    $batch = $delStmt instanceof \PDOStatement ? $delStmt->rowCount() : 0;
    $deleted += $batch;
} while ($batch === 5000);

        $this->logger->info('audit_trail.archive.completed', [
            'channel' => 'audit_trail',
            'cutoff' => $cutoff,
            'archived' => $total,
            'deleted' => $deleted,
            'file' => basename($gzFile),
            'size' => filesize($gzFile),
            'sha256' => hash_file('sha256', $gzFile),
        ]);

        return [
            'archived' => $total,
            'deleted' => $deleted,
            'file' => $gzFile,
            'cutoff' => $cutoff,
            'size' => filesize($gzFile),
        ];
    } catch (\Throwable $e) {
        $this->logger->error('audit_trail.archive.failed', [
            'channel' => 'audit_trail',
            'days' => $days,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return [
            'archived' => 0,
            'deleted' => 0,
            'file' => null,
            'error' => $e->getMessage(),
        ];
    }

}
    public function getForUser(int $userId, int $limit = 50): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT * FROM audit_trail
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ?",
                [$userId, $limit]
            );
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_for_user.failed', [
                'channel' => 'audit_trail',
                'user_id' => $userId,
                'limit' => $limit,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

   public function getAll(
    int $page = 1,
    int $perPage = 50,
    ?string $event = null,
    ?int $userId = null,
    ?string $search = null,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
        try {
            $where = 'WHERE 1=1';
            $params = [];

            if ($event !== null && $event !== '') {
                $where .= ' AND at.event = ?';
                $params[] = $event;
            }

            if ($userId !== null) {
                $where .= ' AND (at.user_id = ? OR at.actor_id = ?)';
                $params[] = $userId;
                $params[] = $userId;
            }
            if ($dateFrom !== null && $dateFrom !== '') {
    $where .= ' AND at.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== null && $dateTo !== '') {
    $where .= ' AND at.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

            if ($search !== null && $search !== '') {
                $where .= ' AND (at.event LIKE ? OR at.context LIKE ? OR u.email LIKE ?)';
                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $offset = ($page - 1) * $perPage;

            $total = (int)$this->db->fetchColumn(
                "SELECT COUNT(*)
                 FROM audit_trail at
                 LEFT JOIN users u ON u.id = at.user_id
                 $where",
                $params
            );

            $rows = $this->db->fetchAll(
                "SELECT at.*,
                        u.full_name AS user_name, u.email AS user_email,
                        a.full_name AS actor_name, a.email AS actor_email
                 FROM audit_trail at
                 LEFT JOIN users u ON u.id = at.user_id
                 LEFT JOIN users a ON a.id = at.actor_id
                 $where
                 ORDER BY at.created_at DESC
                 LIMIT ? OFFSET ?",
                [...$params, $perPage, $offset]
            );

            return [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'totalPages' => (int)ceil($total / max($perPage, 1)),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.get_all.failed', [
                'channel' => 'audit_trail',
                'error' => $e->getMessage(),
            ]);
            return [
                'rows' => [],
                'total' => 0,
                'page' => $page,
                'totalPages' => 0,
            ];
        }
    }

/**
 * لیست انواع eventهای موجود در audit_trail
 */
public function getEventTypes(): array
{
    try {
        $rows = $this->db->fetchAll(
            "SELECT event, COUNT(*) AS total
             FROM audit_trail
             GROUP BY event
             ORDER BY total DESC, event ASC"
        );

        return is_array($rows) ? $rows : [];
    } catch (\Throwable $e) {
        $this->logger->error('audit_trail.get_event_types.failed', [
            'channel' => 'audit_trail',
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return [];
    }
}

/**
 * آمار کلی audit در بازه زمانی
 */
public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
{
    try {
        $where = "WHERE 1=1";
        $params = [];

        if (!empty($dateFrom)) {
            $where .= " AND at.created_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
        }

        if (!empty($dateTo)) {
            $where .= " AND at.created_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }

        $total = $this->countQuery("SELECT COUNT(*) FROM audit_trail at {$where}", $params);

        $uniqueUsers = $this->countQuery(
            "SELECT COUNT(DISTINCT at.user_id)
             FROM audit_trail at
             {$where} AND at.user_id IS NOT NULL",
            $params
        );

        $uniqueActors = $this->countQuery(
            "SELECT COUNT(DISTINCT at.actor_id)
             FROM audit_trail at
             {$where} AND at.actor_id IS NOT NULL",
            $params
        );

        $today = $this->countQuery(
            "SELECT COUNT(*)
             FROM audit_trail at
             {$where} AND DATE(at.created_at) = CURDATE()",
            $params
        );

        return [
            'total' => $total,
            'unique_users' => $uniqueUsers,
            'unique_actors' => $uniqueActors,
            'today' => $today,
        ];
    } catch (\Throwable $e) {
        $this->logger->error('audit_trail.get_stats.failed', [
            'channel' => 'audit_trail',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return [
            'total' => 0,
            'unique_users' => 0,
            'unique_actors' => 0,
            'today' => 0,
        ];
    }
}


private function countQuery(string $sql, array $params = []): int
{
    $stmt = $this->db->query($sql, $params);
    if (!$stmt instanceof \PDOStatement) {
        return 0;
    }

    $value = $stmt->fetchColumn();
    return $value !== false ? (int) $value : 0;
}

    public function cleanup(int $days = 365): int
    {
        try {
            $stmt = $this->db->query(
                "DELETE FROM audit_trail
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$days]
            );
            return $stmt ? $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            $this->logger->error('audit_trail.cleanup.failed', [
                'channel' => 'audit_trail',
                'days' => $days,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function sanitizeEvent(string $event): string
    {
        $event = trim(strtolower($event));
        return mb_substr($event, 0, 100);
    }

    private function sanitizeContext(array $context): array
    {
        $masked = $context;
        $sensitive = ['password', 'token', 'api_key', 'secret', 'card_number', 'sheba', 'iban'];

        array_walk_recursive($masked, function (&$value, $key) use ($sensitive) {
            $k = strtolower((string)$key);
            foreach ($sensitive as $field) {
                if (str_contains($k, $field)) {
                    $value = '***MASKED***';
                    return;
                }
            }

            if (is_string($value) && mb_strlen($value) > 2000) {
                $value = mb_substr($value, 0, 2000) . '...';
            }
        });

        return $masked;
    }

    private function currentUserId(): ?int
    {
        try {
            if (function_exists('user_id')) {
                $id = user_id();
                return $id ? (int)$id : null;
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function clientIp(): ?string
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    private function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua ? mb_substr($ua, 0, 300) : null;
    }
}