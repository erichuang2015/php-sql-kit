<?php

namespace kit\sql;

use PDO;
use PDOException;

class Engine
{
  /** @var PDO|null */
  private $pdo;

  /** @var Dsn|null */
  private $dsn;

  /** @var string|null */
  private $driver_name;

  private $connection_attrs;

  private $previous_connection_attrs;

  public function __construct (PDO $pdo = null, DSN $pdo_dsn = null)
  {
    $this->pdo = $pdo;
    $this->dsn = $pdo_dsn;

    $this->previous_connection_attrs = null;

    $this->connection_attrs = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_CASE => PDO::CASE_NATURAL,
      PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
  }

  public static function from_pdo (Pdo $pdo)
  {
    return new static($pdo, null);
  }

  public static function from_dsn (DSN $dsn)
  {
    return new static(null, $dsn);
  }

  public static function from_db_source (DB_source $db_source)
  {
    return new static(null, $db_source->to_dsn());
  }

  public function delete ($table_name, array $where_mappings)
  {
    if (empty($where_mappings)) throw new \InvalidArgumentException();
    $table_name = $this->quote_table_name($table_name);

    $values_to_bind = [];
    $where_predicates = [];
    foreach ($where_mappings as $where_col => $where_val) {
      $where_predicates[] = $this->quote_identifier($where_col) . ' = ?';
      $values_to_bind[] = $where_val;
    }

    $stmt = 'DELETE FROM ' . $table_name . ' WHERE ' . implode(' AND ', $where_predicates) . ';';

    return $this->exec($stmt, $values_to_bind);
  }

  public function update ($table_name, array $update_mappings, array $where_mappings)
  {
    if (empty($update_mappings)) throw new \InvalidArgumentException();
    if (empty($where_mappings)) throw new \InvalidArgumentException();

    $table_name = $this->quote_table_name($table_name);
    $values_to_bind = [];
    $set_directives = [];

    foreach ($update_mappings as $update_col => $update_val) {
      // create an individual directive with the column name and a placeholder for the value
      $set_directives[] = $this->quote_identifier($update_col) . ' = ?';
      // and remember which value to bind here
      $values_to_bind[] = $update_val;
    }

    $where_predicates = [];
    foreach ($where_mappings as $where_col => $where_val) {
      // create an individual predicate with the column name and a placeholder for the value
      $where_predicates[] = $this->quote_identifier($where_col) . ' = ?';
      // and remember which value to bind here
      $values_to_bind[] = $where_val;
    }

    $stmt = 'UPDATE ' . $table_name . ' SET ' . implode(', ', $set_directives) . ' WHERE ' . implode(' AND ', $where_predicates) . ';';

    return $this->exec($stmt, $values_to_bind);
  }

  public function select_row ($query, $values_to_bind = null)
  {
    return $this->_select(function ($stmt) {
      return $stmt->fetch();
    }, $query, $values_to_bind);
  }

  private function _select (callable $callback, $query, array $values_to_bind = null)
  {
    $this->connect();

    try {
      $prepared_stmt = $this->pdo->prepare($query);
    } catch (PDOException $e) {
      self::rethrow($e);
    }

    try {

      $prepared_stmt->execute($values_to_bind);

    } catch (PDOException $e) {
      self::rethrow($e);
    }

    $results = $callback($prepared_stmt);

    $this->disconnect();

    if (empty($results) && $prepared_stmt->rowCount() === 0) {
      // consistently return `null`
      return null;
    }

    return $results;
  }

  public function insert ($table_name, $mappings)
  {
    $table_name = $this->quote_table_name($table_name);

    $column_names = array_keys($mappings);
    $column_names = $this->escape_column_names($column_names);
    $column_list = implode(', ', $column_names);
    $values = array_fill(0, count($mappings), '?');
    $placeholder_list = implode(', ', $values);

    $statement = 'INSERT INTO ' . $table_name . ' (' . $column_list . ') VALUES (' . $placeholder_list . ');';

    $this->exec($statement, array_values($mappings));
  }

  private function ensure_connected ()
  {
    if ($this->pdo === null) {
      try {
        $this->pdo = new \PDO($this->dsn, $this->dsn->username, $this->dsn->password);
      } catch (PDOException $e) {
        self::rethrow($e);
      }
    }

    if ($this->driver_name === null) {
      $this->driver_name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
  }

  private function connect ()
  {
    $this->ensure_connected();

    $this->configure_connection($this->connection_attrs, $this->previous_connection_attrs);
  }

  private function disconnect ()
  {
    $this->configure_connection($this->previous_connection_attrs, $this->connection_attrs);
  }

  private function configure_connection (array &$attrs = null, array &$previous_attrs = null)
  {
    // TODO
  }

  public function exec ($statement, array $values_to_bind = null)
  {
    $this->connect();

    try {
      $prepared_stmt = $this->pdo->prepare($statement);
    } catch (PDOException $e) {
      self::rethrow($e);
    }

    try {
      $prepared_stmt->execute($values_to_bind);
    } catch (PDOException $e) {
      self::rethrow($e);
    }

    $affected_rows = $prepared_stmt->rowCount();

    $this->disconnect();

    return $affected_rows;
  }

  private function quote_table_name ($table_name)
  {
    if (\is_array($table_name)) {
      $table_name = \array_map([$this, 'quote_identifier'], $table_name);
      return \implode('.', $table_name);
    }

    return $this->quote_identifier($table_name);
  }

  private function quote_identifier ($identifier)
  {
    $this->ensure_connected();

    $is_mysql = $this->driver_name == DB_source::DRIVER_NAME_MYSQL;
    $char = $is_mysql ? '`' : '"';
    return $char . str_replace($char, $char . $char, $identifier) . $char;
  }

  private function escape_column_names ($column_names)
  {
    return array_map([$this, 'quote_identifier'], $column_names);
  }

  public function last_insert_id ($sequence_name = null)
  {
    $this->connect();
    $id = $this->pdo->lastInsertId($sequence_name);
    $this->disconnect();
    return $id;
  }

  public static function rethrow (PDOException $e)
  {
    $error_class = null;
    $error_code = null;
    if (!empty($e->getCode())) {
      // error code available
      $error = $e->getCode();
    }


    // TODO

    throw new \Exception($e->getMessage());
  }
}
