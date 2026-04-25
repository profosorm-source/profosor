<?php

declare(strict_types=1);
namespace Core;

/**
 * Query Builder
 * 
 * ساخت Query به صورت شیء‌گرا
 */
class QueryBuilder
{
    private $pdo;
    private $table;
    private $select = ['*'];
    private $where = [];
    private $bindings = [];
    private $orderBy = [];
    private $limit;
    private $offset;
    private $join = [];
    
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validate نام جدول برای جلوگیری از SQL Injection
     */
    private function validateTableName($table)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException("نام جدول غیرمجاز: {$table}");
        }
        return $table;
    }

    /**
     * Validate نام ستون برای جلوگیری از SQL Injection
     */
    private function validateColumnName($column)
    {
        // فرمت: table.column یا column یا COUNT(*) و غیره
        if (!preg_match('/^[a-zA-Z0-9_.*]+$/', $column)) {
            throw new \InvalidArgumentException("نام ستون غیرمجاز: {$column}");
        }
        return $column;
    }

    /**
     * تنظیم جدول
     */
    public function table($table)
    {
        $this->table = $this->validateTableName($table);
        $this->reset();
        return $this;
    }

    /**
     * انتخاب ستون‌ها
     */
    public function select(...$columns)
    {
        // اگر یکی از columns آرایه بود (مثل ['col1', 'col2'])
        if (count($columns) === 1 && is_array($columns[0])) {
            $columns = $columns[0];
        }
        
        // Validate هر ستون
        foreach ($columns as $column) {
            $this->validateColumnName($column);
        }
        
        $this->select = $columns;
        return $this;
    }

    /**
     * شرط WHERE
     */
    public function where($column, $operator = '=', $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * شرط OR WHERE
     */
    public function orWhere($column, $operator = '=', $value = null)
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        
        $this->where[] = [
            'type' => 'OR',
            'column' => $column,
            'operator' => $operator,
            'value' => $value
        ];
        
        return $this;
    }

    /**
     * WHERE IN
     */
    public function whereIn($column, array $values)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IN',
            'value' => $values
        ];
        
        return $this;
    }

    /**
     * WHERE NULL
     */
    public function whereNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * WHERE NOT NULL
     */
    public function whereNotNull($column)
    {
        $this->where[] = [
            'type' => 'AND',
            'column' => $column,
            'operator' => 'IS NOT NULL',
            'value' => null
        ];
        
        return $this;
    }

    /**
     * JOIN
     */
    public function join($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'INNER',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * LEFT JOIN
     */
    public function leftJoin($table, $first, $operator, $second)
    {
        $this->join[] = [
            'type' => 'LEFT',
            'table' => $this->validateTableName($table),
            'first' => $this->validateColumnName($first),
            'operator' => $this->validateOperator($operator),
            'second' => $this->validateColumnName($second)
        ];
        
        return $this;
    }

    /**
     * Validate عملگر برای جلوگیری از SQL Injection
     */
    private function validateOperator($operator)
    {
        $allowedOps = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'IS NULL', 'IS NOT NULL'];
        $op = strtoupper($operator);
        if (!in_array($op, $allowedOps, true)) {
            throw new \InvalidArgumentException("عملگر غیرمجاز: {$operator}");
        }
        return $op;
    }

    /**
     * ORDER BY
     */
    public function orderBy($column, $direction = 'ASC')
    {
        // جلوگیری از SQL Injection: فقط کاراکترهای مجاز در نام ستون
        $this->validateColumnName($column);
        
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    /**
     * LIMIT
     */
    public function limit($limit)
    {
        if (!is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException("LIMIT باید عدد مثبت باشد");
        }
        $this->limit = $limit;
        return $this;
    }

    /**
     * OFFSET
     */
    public function offset($offset)
    {
        if (!is_int($offset) || $offset < 0) {
            throw new \InvalidArgumentException("OFFSET باید عدد غیرمنفی باشد");
        }
        $this->offset = $offset;
        return $this;
    }

    /**
     * دریافت همه رکوردها
     */
    public function get()
    {
        $sql = $this->buildSelectQuery();
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.builder.query.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'bindings' => $this->bindings ?? [],
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder query failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * دریافت اولین رکورد
     */
    public function first()
{
    $this->limit = 1;
    $results = $this->get();
    
    if (empty($results)) {
        return null;
    }
    
    // تبدیل آرایه به Object
    return (object) $results[0];
}

    /**
     * دریافت با ID
     */
    public function find($id)
    {
        return $this->where('id', $id)->first();
    }

    /**
     * شمارش
     */
    public function count()
    {
        // FIX C-4: count() مقدار select و limit را ذخیره می‌کند،
        // سپس بعد از اتمام کار آن‌ها را بازیابی می‌کند.
        // قبلاً first() صدا زده می‌شد که limit را به 1 تبدیل می‌کرد
        // و بعد از بازیابی select، limit همچنان 1 باقی می‌ماند.
        $originalSelect = $this->select;
        $originalLimit  = $this->limit;

        $this->select = ['COUNT(*) as count'];
        $this->limit  = null;

        $sql = $this->buildSelectQuery();

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($this->bindings);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
        } catch (\PDOException $e) {
            $this->select = $originalSelect;
            $this->limit  = $originalLimit;
            throw $e;
        }

        $this->select = $originalSelect;
        $this->limit  = $originalLimit;

        return (int)($result->count ?? 0);
    }

    /**
     * INSERT
     */
    public function insert(array $data)
{
    if (empty($this->table)) {
        throw new \Exception('No table selected for insert.');
    }
    if (empty($data)) {
        throw new \Exception('Insert data is empty.');
    }

    $columns = \array_keys($data);
    $values  = \array_values($data);

    // Validate هر ستون
    foreach ($columns as $column) {
        $this->validateColumnName($column);
    }

    $placeholders = \array_fill(0, \count($columns), '?');

    // بک‌تیک برای ستون‌ها (ایمن‌تر)
    $colsSql = '`' . \implode('`,`', $columns) . '`';

    // بک‌تیک برای نام جدول (فرض: table از داخل سیستم set شده)
    $sql = "INSERT INTO `{$this->table}` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

    try {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    } catch (\PDOException $e) {
        // ✅ Safe logging
        try {
            if (function_exists('logger')) {
                logger()->error('database.insert.failed', [
                    'channel' => 'database',
                    'sql' => $sql ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        } catch (\Throwable $logError) {
            error_log('QueryBuilder insert failed: ' . $e->getMessage());
        }
        throw $e;
    }

    // تلاش برای گرفتن ID
    $id = $this->pdo->lastInsertId();

    // اگر عددی بود برگردان (برای اینکه create بتواند find کند)
    if ($id !== '' && \ctype_digit((string)$id)) {
        return (int)$id;
    }

    // اگر جدول auto-inc ندارد
    return true;
}

    /**
     * UPDATE
     */
    public function update(array $data)
    {
        $sets = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            // رفع باگ #20: sanitize نام ستون برای جلوگیری از SQL Injection
            $this->validateColumnName($column);
            $sets[] = "`{$column}` = ?";
            $bindings[] = $value;
        }
        
        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $sets);
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.update.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'data' => $data ?? [],
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder update failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * DELETE - باید حداقل یک WHERE clause وجود داشته باشد
     */
    public function delete()
    {
        // جلوگیری از DELETE بدون WHERE (حذف تمام رکوردها)
        if (empty($this->where)) {
            throw new \Exception('DELETE بدون WHERE clause مجاز نیست. برای حذف تمام رکوردها از: DB::table("users")->where("1", "=", "1")->delete()');
        }

        $sql = "DELETE FROM `{$this->table}`";
        $bindings = [];
        
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($bindings);
        }
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            // ✅ Safe logging
            try {
                if (function_exists('logger')) {
                    logger()->error('database.delete.failed', [
                        'channel' => 'database',
                        'sql' => $sql ?? null,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                }
            } catch (\Throwable $logError) {
                error_log('QueryBuilder delete failed: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * ساخت SELECT Query
     */
    private function buildSelectQuery()
    {
        // استفاده از backticks برای جلوگیری از SQL Injection
        $selectCols = implode(', ', array_map(function($col) {
            // اگر ستون حاوی * باشد (مثل COUNT(*)) نباید backtick شود
            if (strpos($col, '*') !== false) {
                return $col;
            }
            // اگر شامل . باشد (table.column)
            if (strpos($col, '.') !== false) {
                $parts = explode('.', $col);
                return '`' . $parts[0] . '`.`' . $parts[1] . '`';
            }
            return '`' . $col . '`';
        }, $this->select));

        $sql = "SELECT {$selectCols} FROM `{$this->table}`";
        
        // JOIN
        if (!empty($this->join)) {
            foreach ($this->join as $join) {
                $sql .= " {$join['type']} JOIN `{$join['table']}` ON {$join['first']} {$join['operator']} {$join['second']}";
            }
        }
        
        // WHERE
        if (!empty($this->where)) {
            $sql .= $this->buildWhereClause($this->bindings);
        }
        
        // ORDER BY
        if (!empty($this->orderBy)) {
            $sql .= " ORDER BY ";
            $orders = [];
            foreach ($this->orderBy as $order) {
                // اضافه کردن backticks برای ستون
                $col = strpos($order[0], '.') !== false 
                    ? str_replace('.', '`.`', '`' . $order[0] . '`')
                    : '`' . $order[0] . '`';
                $orders[] = "{$col} {$order[1]}";
            }
            $sql .= implode(', ', $orders);
        }
        
        // LIMIT
        if ($this->limit !== null) {
            $sql .= " LIMIT " . (int)$this->limit;
        }
        
        // OFFSET
        if ($this->offset !== null) {
            $sql .= " OFFSET " . (int)$this->offset;
        }
        
        return $sql;
    }

    /**
     * ساخت WHERE Clause
     */
    private function buildWhereClause(&$bindings)
    {
        $sql = " WHERE ";
        $conditions = [];
        
        foreach ($this->where as $index => $condition) {
            $type = $index === 0 ? '' : " {$condition['type']} ";
            
            // رفع باگ #20: sanitize نام ستون در WHERE clause
            $col = $condition['column'];
            $this->validateColumnName($col);
            
            // sanitize operator - استفاده از method موجود
            $op = $this->validateOperator($condition['operator']);
            
            // اضافه کردن backticks برای ستون
            if (strpos($col, '.') !== false) {
                $col = str_replace('.', '`.`', '`' . $col . '`');
            } else {
                $col = '`' . $col . '`';
            }
            
            if ($op === 'IN') {
                $placeholders = array_fill(0, count($condition['value']), '?');
                $conditions[] = $type . "{$col} IN (" . implode(', ', $placeholders) . ")";
                $bindings = array_merge($bindings, $condition['value']);
            } elseif ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $conditions[] = $type . "{$col} {$op}";
            } else {
                $conditions[] = $type . "{$col} {$op} ?";
                $bindings[] = $condition['value'];
            }
        }
        
        $sql .= implode('', $conditions);
        
        return $sql;
    }

    /**
     * Reset کردن Query
     */
    private function reset()
    {
        $this->select = ['*'];
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;
        $this->join = [];
    }
}