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

    private $_clients = array();
    private $_applications = array();
    // For group ability
    private $_groups = array();

    public function __construct($host = 'localhost', $port = 8000, $max = 100)
    {
        parent::__construct($host, $port, $max);

        $this->log('Server created');
    }

    public function run()
    {
        while (true) {
            $changed_sockets = $this->allsockets;
            @socket_select($changed_sockets, $write = NULL, $except = NULL, 1);
            foreach ($this->_applications as $application) {
                $application->onTick();
            }
            foreach ($changed_sockets as $socket) {
                if ($socket == $this->master) {
                    if (($ressource = socket_accept($this->master)) < 0) {
                        $this->log('Socket error: ' . socket_strerror(socket_last_error($ressource)));
                        continue;
                    } else {
                        $client = new Connection($this, $ressource);
                        $this->_clients[$ressource] = $client;
                        $this->allsockets[] = $ressource;
                    }
                } else {
                    $client = $this->_clients[$socket];
                    $bytes = @socket_recv($socket, $data, 4096, 0);
                    if ($bytes === 0) {
                        $client->onDisconnect();
                        unset($this->_clients[$socket]);
                        $index = array_search($socket, $this->allsockets);
                        unset($this->allsockets[$index]);
                        unset($client);
                    } else {
                        $client->onData($data);
                    }
                }
            }
        }
    }

    public function getApplication($key)
    {
        if (array_key_exists($key, $this->_applications)) {
            return $this->_applications[$key];
        } else {
            return false;
        }
    }

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
    protected function send($message)
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
    protected function broadcast($excludeConnection, $message)
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
    protected function socketDisconnect($connect)
    {
        $key = array_search($connect, $this->_clients);
        if ($key) {
            unset($this->_clients[$key]);
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
    protected function sendGroup($message, $key)
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
    protected function broadcastGroup($message, $excludeConnection, $key)
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

}
