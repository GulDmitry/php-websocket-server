<?php

namespace WebSocket;

/**
 * Simple WebSockets server
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Dmitru Gulyakevich
 */
class Server extends Socket
{

    // Contain: socket obj (key) => Connection obj (value)
    private $_clients = array();
    private $_applications = array();
    // For group ability
    private $_groups = array();
    private $_debug = false;

    public function __construct($host = 'localhost', $port = 8000, $max = 100)
    {
        parent::__construct($host, $port, $max);

        $this->log('Server created');
    }

    public function run()
    {
        while (true) {
            // open sockets. Contain 1 master socket
            $changed_sockets = $this->_allsockets;
            @socket_select($changed_sockets, $write = NULL, $except = NULL, 1);
            foreach ($this->_applications as $application) {
                $application->onTick();
            }
            foreach ($changed_sockets as $socket) {
                // client connect first time
                if ($socket == $this->_master) {
                    $resource = socket_accept($this->_master);
                    if ($resource < 0) {
                        $this->log('Socket error: ' . socket_strerror(socket_last_error($resource)));
                        continue;
                    } else {
                        $client = new Connection($this, $resource);
                        $this->_clients[$resource] = $client;
                        $this->_allsockets[] = $resource;
                    }
                    // client send message or disconnect
                } else {
                    $client = $this->_clients[$socket];
                    $bytes = socket_recv($socket, $data, 4096, 0);

                    //client disconnected
                    if ($bytes === 0) {
                        $client->onDisconnect();
                        unset($client);
                    } else {
                        // $data - all data with headers
                        $client->onData($data);
                    }
                }
            }
        }
    }

    /**
     * Enable\Disable debug mode
     *
     * @param bool $flag
     */
    public function setDebug($flag)
    {
        $this->_debug = (bool) $flag;
    }

    /**
     * Get debug mode
     *
     * @return bool
     */
    public function getDebug()
    {
        return (bool) $this->_debug;
    }

    /**
     * Get Application by register name.
     *
     * @param string $key
     * @return mixed Application object | false if app not exists
     */
    public function getApplication($key)
    {
        if (array_key_exists($key, $this->_applications)) {
            return $this->_applications[$key];
        } else {
            return false;
        }
    }

    /**
     * Register application
     *
     * @param string $key App name
     * @param object $application Class.
     */
    public function registerApplication($key, $application)
    {
        $this->_applications[$key] = $application;
    }

    /**
     * Console log
     *
     * @param string $message
     * @param string $type
     */
    public function log($message, $type = 'info')
    {
        echo date('Y-m-d H:i:s') . ' [' . ($type ? $type : 'error') . '] ' . $message . PHP_EOL;
    }

    /**
     * Sends data from the current connection to all other connections on the _server_
     *
     * @param string $message
     */
    public function send($message)
    {
        foreach ($this->_clients as $v) {
            $v->send($message);
        }
    }

    /**
     * Sends data from the current connection to all other connections on the _server_,
     * excluding the sending connection.
     *
     * @param Connection $excludeConnection
     * @param string $message
     */
    public function broadcast($excludeConnection, $message)
    {
        $clientKey = array_search($excludeConnection, $this->_clients);

        foreach ($this->_clients as $k => $v) {

            if ($k == $clientKey) {
                continue;
            }

            $v->send($message);
        }
    }

    /**
     * Delete connection from server
     *
     * @param Connection $connect
     */
    public function socketDisconnect($connect)
    {
        $socketKey = array_search($connect, $this->_clients);

        $sKey = array_search($socketKey, $this->_allsockets);

        if ($socketKey && $sKey) {
            unset($this->_clients[$socketKey]);
            unset($this->_allsockets[$sKey]);
        }

        // remove from group
        $groupKey = $connect->getGroup();
        if ($groupKey) {
            unset($this->_groups[$groupKey][array_search($connect, $this->_groups[$groupKey])]);
        }
    }

    /**
     * @param mixed $key
     * @param Connection $connection
     */
    public function addToGroup($key, $connection)
    {
        $this->_groups[$key][] = $connection;
    }

    /**
     * Get connections by group key
     *
     * @param mixed $key
     * @return mixed Connections in group or null
     */
    public function getGroupByKey($key)
    {
        return array_key_exists($key, $this->_groups) ? $this->_groups[$key] : false;
    }

    /**
     * Send to current connection group if $key = false
     *
     * @param mixed $key Group identifier
     * @param string $message
     */
    public function sendGroup($message, $key)
    {
        if ($key === false) {
            return;
        }

        foreach ($this->_groups[$key] as $v) {
            $v->send($message);
        }
    }

    /**
     * Broadcast to current connection group if $key = false
     *
     * @param string $message
     * @param Connection $excludeConnectio
     * @param mixed $key Group identifier
     */
    public function broadcastGroup($message, $excludeConnection, $key)
    {
        if ($key === false) {
            return;
        }

        $excludeKey = array_search($excludeConnection, $this->_groups[$key]);
        foreach ($this->_groups[$key] as $k => $v) {

            if ($k == $excludeKey) {
                continue;
            }

            $v->send($message);
        }
    }

    /**
     * Return connections and sockets (with master socket) count
     *
     * @return array ['cliets', 'sockets']
     */
    public function showSocketsInfoCount()
    {
        return array(
            'clients' => count($this->_clients),
            'sockets' => count($this->_allsockets)
        );
    }

}
