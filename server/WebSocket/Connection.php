<?php

namespace WebSocket;

/**
 * WebSocket Connection class
 *
 * @author Nico Kaiser <nico@kaiser.me>
 * @author Simon Samtleben <web@lemmingzshadow.net> (Added hybi10 support)
 * @author Dmitru Gulyakevich
 */
class Connection
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
    private function _handshake($data)
    {
        $protocol = null;
        $this->log('Performing handshake');

        $lines = preg_split("/\r\n/", $data);
        if (count($lines) && preg_match('/<policy-file-request.*>/', $lines[0])) {
            $this->log('Flash policy file request');
            $this->_serverFlashPolicy();
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

        $this->_application = $this->_server->getApplication(substr($path, 1)); // e.g. '/echo'
        if (!$this->_application) {
            $this->log('Invalid application: ' . $path);
            socket_close($this->_socket);
            return false;
        }

        $status = '101 Web Socket Protocol Handshake';

        if (array_key_exists('Sec-WebSocket-Key', $headers) && $headers['Sec-WebSocket-Version'] >= 6) {

            $protocol = 'draft-ietf-hybi-protocol-10';
            //http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10

            $def_header = array(
                'Sec-WebSocket-Accept' => $this->_securityDigest($headers['Sec-WebSocket-Key']),
                'Sec-WebSocket-Protocol' => substr($path, 1)
            );
        } else {
            $this->log('Incorrect headers or old protocol. Use hubi-draft 07+');
            socket_close($this->_socket);
            return false;
        }


        $header_str = '';
        foreach ($def_header as $key => $value) {
            $header_str .= $key . ': ' . $value . "\r\n";
        }

        $upgrade = "HTTP/1.1 ${status}\r\n" .
                "Upgrade: WebSocket\r\n" .
                "Connection: Upgrade\r\n" .
                "${header_str}\r\n";

        socket_write($this->_socket, $upgrade, strlen($upgrade));

        $this->_handshaked = true;
        $this->log('Handshake sent, protocol: ' . $protocol);

        $this->_application->onConnect($this);

        return true;
    }

    public function onData($data)
    {
        if ($this->_handshaked) {
            $this->_handle($data);
        } else {
            $this->_handshake($data);
        }
    }

    /**
     * See http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#section-4.2
     * A single-frame unmasked text message
     *    0x81 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains "Hello")
     * A single-frame masked text message
     *    0x81 0x85 0x37 0xfa 0x21 0x3d 0x7f 0x9f 0x4d 0x51 0x58  (contains "Hello")
     * A fragmented unmasked text message
     *    0x01 0x03 0x48 0x65 0x6c (contains "Hel")
     *    0x80 0x02 0x6c 0x6f (contains "lo")
     * Ping request and response
     *    0x89 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains a body of "Hello", but the contents of the body are arbitrary)
     *    0x8a 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains a body of "Hello", matching the body of the ping)
     * 256 bytes binary message in a single unmasked frame
     *    0x82 0x7E 0x0100 [256 bytes of binary data]
     * 64KiB binary message in a single unmasked frame
     *    0x82 0x7F 0x0000000000010000 [65536 bytes of binary data]
     * 0x81 - chr(129);
     * 0x01 - chr(1);
     * 0x80 - chr(128);
     * 0x89 - chr(137);
     * 0x8a - chr(138);
     * 0x82 - chr(130);
     *
     * @param string $data
     */
    private function _handle($data)
    {
        $decodedData = '';

        if (
                strpos($data, chr(129)) !== false ||
                strpos($data, chr(1)) !== false ||
                strpos($data, chr(130) !== false)
        ) {
            $decodedData = $this->_hybi10Decode($data);
        } else if (strpos($data, chr(137)) !== false) {
            $decodedData = pack('C', 0x8a) . $this->_hybi10Decode($data);
            $this->send($decodedData);
            socket_close($this->_socket);
            return false;
        } else {
            $this->log('Data incorrectly framed. Dropping connection');
            socket_close($this->_socket);
            return false;
        }

        $this->log($decodedData);

        $this->_application->onData($decodedData, $this);
        return true;
    }

    /**
     * Work if port 843 listen as root
     */
    private function _serverFlashPolicy()
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
        $encodedData = $this->_hybi10Encode($data);
        if (!@socket_write($this->_socket, $encodedData, strlen($encodedData))) {
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
        $this->log('Disconnected');

        if ($this->_application) {
            // remove from app stack
            $this->_application->onDisconnect($this);
        }
        // remove from server stack
        $this->_server->socketDisconnect($this);

        socket_close($this->_socket);
    }

    private function _securityDigest($key)
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * Console log. Work in debug mode
     *
     * @param string $message
     * @param string $type = 'info'
     */
    public function log($message, $type = 'info')
    {
        if ($this->_server->getDebug()) {
            socket_getpeername($this->_socket, $addr, $port);
            $this->_server->log('[client ' . $addr . ':' . $port . '] ' . $message, $type);
        }
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
            $this->log('Connection join to group: ' . $key);
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

    private function _hybi10Encode($data)
    {
        $frame = Array();
        $mask = array(rand(0, 255), rand(0, 255), rand(0, 255), rand(0, 255));
        $encodedData = '';
        $frame[0] = 0x81;
        $dataLength = strlen($data);


        if ($dataLength <= 125) {
            $frame[1] = $dataLength + 128;
        } else {
            $frame[1] = 254;
            $frame[2] = $dataLength >> 8;
            $frame[3] = $dataLength & 0xFF;
        }
        $frame = array_merge($frame, $mask);
        for ($i = 0; $i < strlen($data); $i++) {
            $frame[] = ord($data[$i]) ^ $mask[$i % 4];
        }

        for ($i = 0; $i < sizeof($frame); $i++) {
            $encodedData .= chr($frame[$i]);
        }

        return $encodedData;
    }

    private function _hybi10Decode($data)
    {
        $bytes = $data;
        $dataLength = '';
        $mask = '';
        $coded_data = '';
        $decodedData = '';
        $secondByte = sprintf('%08b', ord($bytes[1]));
        $masked = ($secondByte[0] == '1') ? true : false;
        $dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);

        if ($masked === true) {
            if ($dataLength === 126) {
                $mask = substr($bytes, 4, 4);
                $coded_data = substr($bytes, 8);
            } elseif ($dataLength === 127) {
                $mask = substr($bytes, 10, 4);
                $coded_data = substr($bytes, 14);
            } else {
                $mask = substr($bytes, 2, 4);
                $coded_data = substr($bytes, 6);
            }
            for ($i = 0; $i < strlen($coded_data); $i++) {
                $decodedData .= $coded_data[$i] ^ $mask[$i % 4];
            }
        } else {
            if ($dataLength === 126) {
                $decodedData = substr($bytes, 4);
            } elseif ($dataLength === 127) {
                $decodedData = substr($bytes, 10);
            } else {
                $decodedData = substr($bytes, 2);
            }
        }

        return $decodedData;
    }

}