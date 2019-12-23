<?php

namespace kit\sql;

final class DB_source
{
  const DRIVER_NAME_POSTGRESQL = 'pgsql';
  const DRIVER_NAME_MYSQL = 'mysql';
  const DRIVER_NAME_SQLITE = 'sqlite';
  const HOST_LOOPBACK_NAME = 'localhost';
  const HOST_LOOPBACK_IP = '127.0.0.1';
  const HOST_DEFAULT = self::HOST_LOOPBACK_IP;

  private $driver_name;

  public $hostname;
  public $port;
  public $db_name;
  public $charset;
  public $username;
  public $password;

  public function __construct ($driver_name)
  {
    $this->driver_name = (string)$driver_name;
    $this->hostname = self::HOST_DEFAULT;
    $this->port = self::suggest_port_from_driver_name($driver_name);
    $this->charset = self::suggest_charset_from_driver_name($driver_name);
  }

  private static function suggest_port_from_driver_name ($driver_name)
  {
    switch ($driver_name) {
      case self::DRIVER_NAME_MYSQL:
        return 3306;
      case self::DRIVER_NAME_POSTGRESQL:
        return 5432;
      default:
        return null;
    }
  }

  private static function suggest_charset_from_driver_name ($driver_name)
  {
    switch ($driver_name) {
      case self::DRIVER_NAME_MYSQL:
        return 'utf8mb4';
      case self::DRIVER_NAME_POSTGRESQL:
        return 'UTF8';
      default:
        return null;
    }
  }

  public function to_dsn ()
  {
    if (isset($this->hostname)) {
      $hostname = $this->hostname;
      if ($this->hostname === self::HOST_LOOPBACK_NAME) {
        if (isset($this->port) && $this->port !== self::suggest_port_from_driver_name($this->driver_name)) {
          $hostname = self::HOST_LOOPBACK_IP;
        }
      }

      $components[] = 'host=' . $hostname;
    }

    if (isset($this->port)) {
      $components[] = 'port=' . $this->port;
    }

    if (isset($this->db_name)) {
      $components[] = 'dbname=' . $this->db_name;
    }

    if (isset($this->charset)) {
      if ($this->driver_name === self::DRIVER_NAME_POSTGRESQL) {
        $components[] = 'client_encoding=' . $this->charset;
      } else {
        $components[] = 'charset=' . $this->charset;
      }
    }

    if (isset($this->username)) {
      $components[] = 'user=' . $this->username;
    }
    if (isset($this->password)) {
      $components[] = 'password=' . $this->password;
    }
    $dsn_str = $this->driver_name . ':' . implode(';', $components);
    return new Dsn($dsn_str, $this->username, $this->password);
  }
}
