<?php

namespace WebSocket;

/**
 * WebSocket Connection class
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Dmitru Gulyakevich
 */
class Connection extends Server
{

    private $_server = null;
    private $_socket = null;
    private $_handshaked = false;
    private $_application = null;
    // A numeric counter for custom needs
    private $_messagesStack = 0;
    // Connection group identification
    private $_group = false;


    public function __construct($server, $socket)
    {
        $this->_server = $server;
        $this->_socket = $socket;

        $this->log('Connected');
    }

    /**
     * Once for user connection
     * 
     * @param string $data
     * @return bool 
     */
    private function handshake($data)
    {
        $this->log('Performing handshake');

        $lines = preg_split("/\r\n/", $data);
        if (count($lines) && preg_match('/<policy-file-request.*>/', $lines[0])) {
            $this->log('Flash policy file request');
            $this->serveFlashPolicy();
            return false;
        }

        if (!preg_match('/\AGET (\S+) HTTP\/1.1\z/', $lines[0], $matches)) {
            $this->log('Invalid request: ' . $lines[0]);
            socket_close($this->_socket);
            return false;
        }

        $path = $matches[1];

        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $key3 = '';
        preg_match("#\r\n(.*?)\$#", $data, $match) && $key3 = $match[1];

        $origin = $headers['Origin'];
        $host = $headers['Host'];

        $this->_application = $this->_server->getApplication(substr($path, 1)); // e.g. '/echo'
        if (!$this->_application) {
            $this->log('Invalid application: ' . $path);
            socket_close($this->_socket);
            return false;
        }

        $status = '101 Web Socket Protocol Handshake';
        if (array_key_exists('Sec-WebSocket-Key1', $headers)) {
            // draft-76
            $def_header = array(
                'Sec-WebSocket-Origin' => $origin,
                'Sec-WebSocket-Location' => "ws://{$host}{$path}"
            );
            $digest = $this->securityDigest($headers['Sec-WebSocket-Key1'], $headers['Sec-WebSocket-Key2'], $key3);
        } else {
            // draft-75
            $def_header = array(
                'WebSocket-Origin' => $origin,
                'WebSocket-Location' => "ws://{$host}{$path}"
            );
            $digest = '';
        }
        $header_str = '';
        foreach ($def_header as $key => $value) {
            $header_str .= $key . ': ' . $value . "\r\n";
        }

        $upgrade = "HTTP/1.1 ${status}\r\n" .
                "Upgrade: WebSocket\r\n" .
                "Connection: Upgrade\r\n" .
                "${header_str}\r\n$digest";

        socket_write($this->_socket, $upgrade, strlen($upgrade));

        $this->_handshaked = true;
        $this->log('Handshake sent');

        $this->_application->onConnect($this);

        return true;
    }

    public function onData($data)
    {
        if ($this->_handshaked) {
            $this->handle($data);
        } else {
            $this->handshake($data);
        }
    }

    private function handle($data)
    {
        $chunks = explode(chr(255), $data);

        for ($i = 0; $i < count($chunks) - 1; $i++) {
            $chunk = $chunks[$i];
            if (substr($chunk, 0, 1) != chr(0)) {
                $this->log('Data incorrectly framed. Dropping connection');
                socket_close($this->_socket);

                //if use saved socket in application
                $this->_socket = null;

                //remove from server class
                $this->_server->socketDisconnect($this);

                //onDisconnect application
                $this->_application->onDisconnect($this);
                return false;
            }
            $this->_application->onData(substr($chunk, 1), $this);
        }

        return true;
    }

    /**
     * Work if port 843 licen as root
     */
    private function serveFlashPolicy()
    {
        $policy = '<?xml version="1.0"?>' . "\n";
        $policy .= '<!DOCTYPE cross-domain-policy SYSTEM "http://www.macromedia.com/xml/dtds/cross-domain-policy.dtd">' . "\n";
        $policy .= '<cross-domain-policy>' . "\n";
        $policy .= '<allow-access-from domain="*" to-ports="*"/>' . "\n";
        $policy .= '</cross-domain-policy>' . "\n";
        socket_write($this->_socket, $policy, strlen($policy));
        socket_close($this->_socket);
    }

    /**
     * Send to current socket
     * 
     * @param string $data 
     */
    public function send($data)
    {
        if (!@socket_write($this->_socket, chr(0) . $data . chr(255), strlen($data) + 2)) {
            @socket_close($this->_socket);
            $this->_socket = false;
        }
    }

    /**
     * Send to all server sockets
     * 
     * @param string $message 
     */
    public function sendServer($message)
    {
        $this->_server->send($message);
    }

    /**
     * Broadcats to all server sockets
     * 
     * @param string $message 
     */
    public function broadcastServer($message)
    {
        $this->_server->broadcast($this, $message);
    }

    public function onDisconnect()
    {
        $this->log('Disconnected', 'info');

        if ($this->_application) {
            // remove from app stack
            $this->_application->onDisconnect($this);
            // remove from server stack
            $this->_server->socketDisconnect($this);
        }
        socket_close($this->_socket);
    }

    private function securityDigest($key1, $key2, $key3)
    {
        return md5(
                pack('N', $this->keyToBytes($key1)) .
                pack('N', $this->keyToBytes($key2)) .
                $key3, true);
    }

    /**
     * WebSocket draft 76 handshake by Andrea Giammarchi
     * see http://webreflection.blogspot.com/2010/06/websocket-handshake-76-simplified.html
     * 
     * @param int $key
     */
    private function keyToBytes($key)
    {
        return preg_match_all('#[0-9]#', $key, $number) && preg_match_all('# #', $key, $space) ?
                implode('', $number[0]) / count($space[0]) :
                '';
    }

    /**
     * Console log
     * 
     * @param string $message
     * @param string $type 
     */
    public function log($message, $type = 'info')
    {
        socket_getpeername($this->_socket, $addr, $port);
        $this->_server->log('[client ' . $addr . ':' . $port . '] ' . $message, $type);
    }

    /**
     * Custom counter increment
     * 
     * @param int $num 
     */
    public function incrementMsgStack($num)
    {
        $this->_messagesStack += $num;
    }

    /**
     * Custom counter decrement
     * 
     * @param int $num 
     */
    public function decrementMsgStack($num)
    {
        $this->_messagesStack -= $num;
    }

    /**
     * Reset counter
     */
    public function resetMsgStack()
    {
        $this->_messagesStack = 0;
    }

    /**
     * Socket standing
     * 
     * @return bool TRUE if socket open, else FALSE
     */
    public function getSocketAlive()
    {
        return $this->_socket ? TRUE : FALSE;
    }

    /**
     * Send to current connection group if $key = false
     * 
     * @param mixed $key Group identifier
     * @param string $message 
     */
    public function sendGroup($message, $key = false)
    {
        $grpKey = $key ? $key : $this->_group;
        $this->_server->sendGroup($message, $grpKey);
    }

    /**
     * Broadcast to current connection group if $key = false
     *
     * @param mixed $key Group identifier
     * @param string $message 
     */
    public function broadcastGroup($message, $key = false)
    {
        $grpKey = $key ? $key : $this->_group;
        $this->_server->broadcastGroup($message, $this, $grpKey);
    }

    /**
     *
     * @param mixed $key
     * @param Connection $connection 
     */
    public function setGroup($key)
    {
        // yes, banned group with key '0'
        if ($key != false) {
            $this->_group = $key;
            $this->_server->addToGroup($key, $this);
            $this->log('Connection add in group: ' . $key);
        }
    }

    /**
     * @param mixed $key
     * @return mixe Connections in group with $key, or false if group not exists
     */
    public function getConnectionsByGroupKey($key)
    {
        return $this->_server->getGroupByKey($key);
    }

    /**
     * Get current socket group key
     * 
     * @return mixed Group key or false
     */
    public function getGroup()
    {
        return $this->_group;
    }

}