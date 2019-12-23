<?php

namespace kit\sql;

final class DSN
{
  public $dsn;

  public $username;

  public $password;

  public function __construct ($dsn_str, $username = null, $password = null)
  {
    $this->dsn = (string)$dsn_str;
    $this->username = (string)$username;
    $this->password = (string)$password;

  }

  public function __toString ()
  {
    return $this->dsn;
  }


}
