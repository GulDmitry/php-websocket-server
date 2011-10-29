PHP WebSocket Server
====================

Based on [php-websocket](https://github.com/nicokaiser/php-websocket) by Nico Kaiser

Used websocket [protocol 10](http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10) or higher.

If you want more powerful server see: [Jeff Morgan websocket server](https://github.com/jam1401/PHP-Websockets-Server)

##Changes

### 1. Native namespaces

### 2. New methods

### 3. Draft-ietf-hybi-thewebsocketprotocol-10+

####Connection object.

Send\broadcast to all server, application and group connections.

    /**
     * Send to current socket
     * 
     * @param string $data 
     */
    public function send($data)

-------------------------------------------------------

    /**
     * Send to all server sockets
     * 
     * @param string $message 
     */
    public function sendServer($message)

    /**
     * Broadcats to all server sockets
     * 
     * @param string $message 
     */
    public function broadcastServer($message)

-------------------------------------------------------
    
    /**
     * Custom counter increment
     * 
     * @param int $num 
     */
    public function incrementMsgStack($num)

    /**
     * Custom counter decrement
     * 
     * @param int $num 
     */
    public function decrementMsgStack($num)

    /**
     * Reset counter
     */
    public function resetMsgStack()

-------------------------------------------------------
    
    /**
     * Socket standing
     * 
     * @return bool TRUE if socket open, else FALSE
     */
    public function getSocketAlive()

-------------------------------------------------------
    
    /**
     * Send to current connection group if $key = false
     * 
     * @param mixed $key Group identifier
     * @param string $message 
     */
    public function sendGroup($message, $key = false)


    /**
     * Broadcast to current connection group if $key = false
     *
     * @param mixed $key Group identifier
     * @param string $message 
     */
    public function broadcastGroup($message, $key = false)

    /**
     *
     * @param mixed $key
     * @param Connection $connection 
     */
    public function setGroup($key)

    /**
     * @param mixed $key
     * @return mixe Connections in group with $key, or false if group not exists
     */
    public function getConnectionsByGroupKey($key)

    /**
     * Get current socket group key
     * 
     * @return mixed Group key or false
     */
    public function getGroup()

#### Application class.

    /**
     * Send to application sockets
     * 
     * @param string $message 
     */
    public function sendApp($message)

    /**
     * Send to application sockets with exclude
     *
     * @param Connection $excludeConnection
     * @param string $message 
     */
    public function broadcastApp($excludeConnection, $message)

## Start

    1. Register application(s) (server.php)
    
    2. Start server: php server.php
    Run server script as root (and port 843(!)). If you want custom user or port run other script
    that listens on port 843 and returns a Socket Policy XML string for Flash players.
    (See http://www.adobe.com/devnet/flashplayer/articles/fplayer9_security_04.html for details.)

    2. Connect to applicaton: run client/index.html in browser

## Client

    See client/index.html

## Libraries used

- [php-websocket](https://github.com/nicokaiser/php-websocket) by Nico Kaiser 
- [phpWebSockets](http://code.google.com/p/phpwebsockets/) by Moritz Wutz
- Simon Samtleben <web@lemmingzshadow.net> - hybi 10 encode 
- [web-socket-js](http://github.com/gimite/web-socket-js) by Hiroshi Ichikawa (example)
- [jQuery](http://jquery.com/) (example)
