<?php

class Database
{
    private $host;
    private $db;
    private $user;
    private $pass;
    private $charset = "utf8mb4";
    private $port;

    private $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    private $pdo;
    private $dsn;
    public function initConnection()
    {
        $this->host = gjc_local_config('db_host', 'GJC_DB_HOST');
        $this->port = gjc_local_config('db_port', 'GJC_DB_PORT');
        $this->db   = gjc_local_config('db_name', 'GJC_DB_NAME');
        $this->user = gjc_local_config('db_user', 'GJC_DB_USER');
        $this->pass = gjc_local_config('db_pass', 'GJC_DB_PASS');
        $this->dsn = "mysql:host=$this->host;dbname=$this->db;charset=$this->charset;port=$this->port";
        $this->pdo = null;
        try {
            $this->pdo = new PDO(
                $this->dsn,
                $this->user,
                $this->pass,
                $this->options,
            );
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int) $e->getCode());
        }
        return $this->pdo;
    }
}

$connect = new Database();
$db = $connect->initConnection();
