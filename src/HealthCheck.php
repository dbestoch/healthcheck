<?php

namespace BoosterOps\HealthCheck;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Response;
use Exception;


class HealthCheck
{

    private $responseData        = [];
    private $statusPerService    = [];
    private $systemMetaData      = [];
    private $http_status_code    = 200;
    private $main_status_message = 'ok';
    private $services = [
            'database',
            'redis',
            'cache'
        ];
    private $systemChecks = [
            'storage',
            'memory',
            'iniFile'
        ];


    /**
     * Return the Health Status of the app in JSON.
     *
     * @return jsonresponse
     */
    public function healthStatus()
    {

        $data         = $this->gatherHealthFacts();
        $stutus_code  = $this->getStatusCode();

        return Response::json($data, $stutus_code);

    }


    /**
    * Return the DB Connection name from the Laravel DB config file.
    *
    * @return string
    */
    protected function getDefaultDbConnectionName(): string
    {
        return config('database.default');
    }


    /**
    * Return the DB Connection name from the Laravel DB config file.
    *
    * @return string
    */
    protected function getCacheDriver(): string
    {
        return config('cache.default');
    }


    /**
    * Set the status code property value
    *
    * @param int $statusCode - The HTTP status code sent in request headers
    *
    * @return void
    */
    private function setStatusCode(int $statusCode): void
    {
        $this->http_status_code = $statusCode;
    }


    /**
    * Retrieve the status code property value
    *
    * @return int
    */
    private function getStatusCode(): int
    {
        return $this->http_status_code;
    }


    /**
    * Set the status message property value
    *
    * @param string $statusMessage
    *
    * @return void
    */
    private function setStatusMessage(string $statusMessage): void
    {
        $this->main_status_message = $statusMessage;
    }


    /**
    * Retrieve the status message property value
    *
    * @return string
    */
    private function getStatusMessage(): string
    {
        return $this->main_status_message;
    }


    /**
     * Check to make sure it can connect to the configured database
     *
     * @return void
     */
    private function checkDatabaseConnection(): void
    {

        $dbConnectionName   = $this->getDefaultDbConnectionName();

        try {
            $dbConnection = DB::connection($dbConnectionName)->getPdo();
            $this->setServiceStatus('Database', 'up');
        } catch (Exception $exception) {
            $this->setStatusCode(503);
            $this->setStatusMessage('error');
            $this->setServiceStatus('Database', 'error', $exception->getMessage());
        }

    }


    /**
     * Attempts to connect to Redis and ping expecting a response of 1
     *
     * @return void
     */
    private function checkRedisConnection(): void
    {

        try {
            $redisConnection  = Redis::connection('default')->ping();
            if($redisConnection == 1) {
                $this->setServiceStatus('Redis', 'up');
            } else {
                $this->setStatusCode(503);
                $this->setStatusMessage('error');
                $this->setServiceStatus('Redis', 'error', 'Recieved an invalid response of: ' . $redisConnection);
            }
        } catch (Exception $exception) {
            $this->setStatusCode(503);
            $this->setStatusMessage('error');
            $this->setServiceStatus('Redis', 'error', $exception->getMessage());
        }

    }


    /**
     * Attempts to read and write to the cache
     * and then compares the values to assert they are the same
     *
     * @return void
     */
    protected function checkCacheConnection(): void
    {

        $cacheDriver   = $this->getCacheDriver();
        $expectedValue = Str::random(10);
        $actualValue   = '';

        try {
            Cache::driver($cacheDriver)->put('boosterops-health:check', $expectedValue, 10);
            $actualValue = Cache::driver($cacheDriver)->get('boosterops-health:check');

            if($actualValue != $expectedValue) {
                $this->setStatusCode(503);
                $this->setStatusMessage('error');
                $this->setServiceStatus('Cache', 'error', 'The cached value does not match the expected value');
            } else {
                $this->setServiceStatus('Cache', 'up');
            }

        } catch (Exception $exception) {
            $this->setStatusCode(503);
            $this->setStatusMessage('error');
            $this->setServiceStatus('Cache', 'error', $exception->getMessage());
        }

    }


    /**
     * Checks the current level of storage being use and
     * will issue a warning on the status if it is over 85%
     *
     * @return void
     */
    protected function storageCheck(): void
    {

        $limitPercentage = 85;
        $spaceUsed       = number_format((disk_free_space('/') / disk_total_space('/')) * 100, 1);

        if($spaceUsed >= $limitPercentage){
            $this->setStatusMessage('warning');
            $this->setSystemMetaFact('storage', $spaceUsed, 'warning, the filesytem is more than 85% full');
        } else {
            $this->setSystemMetaFact('storage', 'ok', $spaceUsed . '% used');
        }

    }


    /**
     * Checks the current amount of memory being used by PHP
     *
     * @return void
     */
    protected function memoryCheck(): void
    {
        $limitPercentage = 90;
        $memoryUsed      = (memory_get_usage(true) / 1024) / 1024;
        $memoryLimit     = intval(ini_get('memory_limit'));
        $precentageUsed  = number_format(($memoryUsed / $memoryLimit) * 100, 1);

        if($precentageUsed >= $limitPercentage){
            $this->setStatusMessage('warning');
            $this->setSystemMetaFact('php_memory_used', 'warning', 'warning, the memory is '. $precentageUsed . '% full');
        } else {
            $this->setSystemMetaFact('php_memory_used', 'ok', $precentageUsed . '% used');
        }

    }


    /**
     * Checks the current amount of memory being used by PHP
     *
     * @return void
     */
    protected function iniFileCheck(): void
    {

        $iniFilePath = php_ini_loaded_file();

        if(!$iniFilePath){
            $this->setStatusMessage('warning');
            $this->setSystemMetaFact('php_ini_file', 'warning', 'There is no php.ini file loaded');
        } else {
            $this->setSystemMetaFact('php_ini_file', 'ok', $iniFilePath);
        }

    }


    /**
     * Assembles the overall health facts for the application
     * Database connection, Redis, etc..
     *
     * @return array
     */
    private function gatherHealthFacts(): array
    {

        // Iterate through each service check
        foreach($this->services as $service) {
            $checkFunction = 'check' . ucfirst($service) . 'Connection';
            $this->$checkFunction();
        }

        // Iterate through each system check
        foreach($this->systemChecks as $system) {
            $checkFunction = ucfirst($system) . 'Check';
            $this->$checkFunction();
        }

        return $this->fullSystemHealthStatus();
    }


    /**
     * Set the service status details for a given service
     * and push the details into the main response array
     *
     * @param string $service - The name of the service
     * @param string $status - The status
     * @param string $message - The error message you would like to return in the response
     *
     * @return void
     */
    private function setServiceStatus(string $service, string $status, string $message='good'): void
    {

        $serviceItemStatus = [
                $service =>
                [
                    "status"  => $status,
                    "message" => $message
                ]
            ];

        array_push($this->statusPerService, $serviceItemStatus);

    }


    /**
     * Set the general system status details for host environment
     * and push the details into the main response array
     *
     * @param string $systemItem - The name of the system item
     * @param string $status - The status
     * @param string $message - The message you would like to return in the response
     *
     * @return void
     */
    private function setSystemMetaFact(string $systemItem, string $status, string $message=''): void
    {

        $systemItemStatus = [
                $systemItem =>
                [
                    "status"  => $status,
                    "message" => $message
                ]
            ];

        array_push($this->systemMetaData, $systemItemStatus);

    }


    /**
     * Set the status details for all services
     * and package them in an array
     *
     * @return array
     */
    private function fullSystemHealthStatus(): array
    {

        return $statusInfo = [
                "status"      => $this->getStatusMessage(),
                "status_code" => $this->getStatusCode(),
                "info"        => $this->statusPerService,
                "system_info" => $this->systemMetaData
            ];

    }

}
