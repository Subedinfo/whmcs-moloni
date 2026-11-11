<?php

namespace Moloni\Core;

use Moloni\Exceptions\APIException;
use WHMCS\Database\Capsule;

class DatabaseLock
{
    public static function run($name, callable $callback)
    {
        $pdo = Capsule::connection()->getPdo();
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $mariaDB = stripos($version, 'MariaDB') !== false;
        $version = $mariaDB ? preg_replace('/^5\.5\.5-/', '', $version) : $version;
        $minimum = $mariaDB ? '10.0.2' : '5.7.5';
        if (!preg_match('/^\d+\.\d+(?:\.\d+)?/', $version, $matches) ||
            version_compare($matches[0], $minimum, '<')) {
            throw new APIException('A ligação Moloni requer MySQL 5.7.5+ ou MariaDB 10.0.2+ para bloqueios seguros.', [
                'kind' => 'configuration'
            ], 'lock');
        }

        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $name = 'moloni:' . sha1($database . ':' . $name);
        $statement = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $statement->execute([$name]);

        if ((int)$statement->fetchColumn() !== 1) {
            throw new APIException('Outra operação Moloni está em curso. Tente novamente.', [
                'kind' => 'lock'
            ], 'lock');
        }

        try {
            return $callback();
        } finally {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$name]);

            if ((int)$statement->fetchColumn() !== 1) {
                throw new APIException('Erro ao libertar bloqueio Moloni.', ['kind' => 'lock'], 'lock');
            }
        }
    }
}
