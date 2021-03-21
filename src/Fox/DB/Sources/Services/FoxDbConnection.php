<?php
/*
 * MIT License
 *
 * Copyright (c) 2021 Petr Ploner <petr@ploner.cz>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *
 */

namespace Fox\DB\Sources\Services;

use Fox\Core\Attribute\Autowire;
use Fox\Core\Attribute\Service;
use Fox\Core\Config\AppConfiguration;
use Fox\DB\Helpers\DbEngines\DbEngine;
use Fox\Security\Config\FoxDbExtensionConfigInterface;
use PDO;

#[Service]
#[Autowire]
class FoxDbConnection
{
    private string $dsn;
    private string $username;
    private string $password;
    private string $engine;
    private PDO $pdoConnection;

    public function __construct(AppConfiguration $config, private FoxDBEngineResolver $foxDBEngineResolver)
    {
        /** @var FoxDbExtensionConfigInterface $config */
        $this->dsn = $config->foxDbGetPDODsn();
        $this->engine = explode($this->dsn, ':')[0] ?? throw new InvalidDBConfigurationException();
        $this->username = $config->foxDbUsername();
        $this->password = $config->foxDbPassword();
    }

    public function getPdoConnection(): PDO
    {
        if (!$this->pdoConnection instanceof PDO) {
            $this->connect();
        }

        return $this->pdoConnection;
    }

    public function getDbEngine(): DbEngine
    {
        return $this->foxDBEngineResolver->getDbEngine($this->engine);
    }

    private function connect(): void
    {
        $this->pdoConnection = new PDO($this->dsn,
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,]);
    }

}