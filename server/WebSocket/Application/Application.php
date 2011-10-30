<?php

namespace WebSocket\Application;

/**
 * WebSocket Server Application
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Dmitry Gulyakevich
 */
abstract class Application
{

    protected static $instances = array();
    private $_clients = array();

    /**
     * Singleton.
     */
    protected function __construct()
    {

    }

    /**
     * Singleton.
     */
    final private function __clone()
    {

    }

    /*
     * Singleton.
     */

    final public static function getInstance()
    {
        $calledClassName = get_called_class();
        if (!isset(self::$instances[$calledClassName])) {
            self::$instances[$calledClassName] = new $calledClassName();
        }

        return self::$instances[$calledClassName];
    }

    /**
     *
     * @param object $connection Connection.
     */
    public function onConnect($connection)
    {
        $this->_clients[] = $connection;
    }

    /**
     *
     * @param object $connection Connection.
     */
    public function onDisconnect($connection)
    {
        $key = array_search($connection, $this->_clients);
        if ($key) {
            unset($this->_clients[$key]);
        }
    }

    /**
     * Send to application sockets.
     *
     * @param string $message
     */
    public function sendApp($message)
    {
        foreach ($this->_clients as $v) {

            $v->send($message);
        }
    }

    /**
     * Send to application sockets with exclude.
     *
     * @param Connection $excludeConnection
     * @param string $message
     */
    public function broadcastApp($excludeConnection, $message)
    {
        $excludeKey = array_search($excludeConnection, $this->_clients);

        foreach ($this->_clients as $k => $v) {

            if ($k == $excludeKey) {
                continue;
            }

            $v->send($message);
        }
    }

    /**
     * Tick.
     */
    public function onTick()
    {

    }

    /**
     * Get all application conections.
     *
     * @return array Array of objects (Connection)
     */
    public function getConnections()
    {
        return $this->_clients;
    }

    abstract public function onData($data, $client);
}