<?php
// SPDX-License-Identifier: MIT
// Test-only mysqli-shaped adapter. Does not validate the MySQL migration.
final class BmvjLocalDatabase
{
    public function __construct(private PDO $pdo) {}
    public function prepare(string $sql): BmvjLocalStatement
    {
        // Translate only the MySQL constructs used by the real device gate.
        $sql = str_ireplace('insert ignore into', 'insert or ignore into', $sql);
        $sql = str_ireplace('date_add(now(), interval 30 minute)', "datetime('now', '+30 minutes')", $sql);
        $sql = str_ireplace('now()', "datetime('now')", $sql);
        return new BmvjLocalStatement($this->pdo->prepare($sql));
    }
}

final class BmvjLocalResult
{
    public function __construct(private array $rows) {}
    public function fetch_assoc(): ?array { return array_shift($this->rows); }
    public function fetch_all(int $mode): array { return $this->rows; }
}

final class BmvjLocalMetadata
{
    public function __construct(private array $fields) {}
    public function fetch_field(): object|false
    {
        $name = array_shift($this->fields);
        return $name === null ? false : (object)['name' => $name];
    }
}

final class BmvjLocalStatement
{
    public int $num_rows = 0;
    private array $params = [];
    private array $rows = [];
    private array $bound = [];
    private int $cursor = 0;
    public function __construct(private PDOStatement $statement) {}
    public function bind_param(string $types, &...$params): bool
    {
        $this->params = [];
        foreach ($params as &$param) $this->params[] =& $param;
        return true;
    }
    public function execute(): bool
    {
        $this->statement->execute(array_values($this->params));
        $this->rows = $this->statement->columnCount() ? $this->statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $this->num_rows = count($this->rows);
        $this->cursor = 0;
        return true;
    }
    public function get_result(): BmvjLocalResult { return new BmvjLocalResult($this->rows); }
    public function store_result(): void {}
    public function result_metadata(): BmvjLocalMetadata
    {
        return new BmvjLocalMetadata(array_keys($this->rows[0] ?? []));
    }
    public function bind_result(&...$values): bool
    {
        $this->bound = [];
        foreach ($values as &$value) $this->bound[] =& $value;
        return true;
    }
    public function fetch(): bool
    {
        if (!isset($this->rows[$this->cursor])) return false;
        foreach (array_values($this->rows[$this->cursor++]) as $i => $value) $this->bound[$i] = $value;
        return true;
    }
    public function close(): void { $this->statement->closeCursor(); }
}
