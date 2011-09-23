<?php

namespace WebSocket\Application;

/**
 * Example WebSocket Application
 * @author Dmitru Gulyakevich
 */
class ExampleApplication extends Application
{

//    public function onConnect($connection)
//    {
//        parent::onConnect($connection);
//        
//    }
//
//    public function onDisconnect($connection)
//    {
//        parent::onDisconnect($connection);
//    }
//
//    public function onTick()
//    {
//        //every second
//    }

    public function onData($jData, $client)
    {
        
//         _This section is non-normative._
//
//   o  A single-frame unmasked text message
//
//      *  0x81 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains "Hello")
//
//   o  A single-frame masked text message
//
//      *  0x81 0x85 0x37 0xfa 0x21 0x3d 0x7f 0x9f 0x4d 0x51 0x58
//         (contains "Hello")
//
//   o  A fragmented unmasked text message
//
//      *  0x01 0x03 0x48 0x65 0x6c (contains "Hel")
//
//      *  0x80 0x02 0x6c 0x6f (contains "lo")
//
//   o  Ping request and response
//
//      *  0x89 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains a body of "Hello",
//         but the contents of the body are arbitrary)
//
//      *  0x8a 0x05 0x48 0x65 0x6c 0x6c 0x6f (contains a body of "Hello",
//         matching the body of the ping)
//
//   o  256 bytes binary message in a single unmasked frame
//
//      *  0x82 0x7E 0x0100 [256 bytes of binary data]
//
//   o  64KiB binary message in a single unmasked frame
//
//      *  0x82 0x7F 0x0000000000010000 [65536 bytes of binary data]
        
        
        $bytes = $jData;
	$dataLength = '';
	$mask = '';
	$coded_data = '';
	$decodedData = '';
	$secondByte = sprintf('%08b', ord($bytes[1]));		
	$masked = ($secondByte[0] == '1') ? true : false;		
	$dataLength = ($masked === true) ? ord($bytes[1]) & 127 : ord($bytes[1]);
	if($masked === true)
	{
		if($dataLength === 126)
		{
		   $mask = substr($bytes, 4, 4);
		   $coded_data = substr($bytes, 8);
		}
		elseif($dataLength === 127)
		{
			$mask = substr($bytes, 10, 4);
			$coded_data = substr($bytes, 14);
		}
		else
		{
			$mask = substr($bytes, 2, 4);		
			$coded_data = substr($bytes, 6);		
		}	
		for($i = 0; $i < strlen($coded_data); $i++)
		{		
			$decodedData .= $coded_data[$i] ^ $mask[$i % 4];
		}
	}
	else
	{
		if($dataLength === 126)
		{		   
		   $decodedData = substr($bytes, 4);
		}
		elseif($dataLength === 127)
		{			
			$decodedData = substr($bytes, 10);
		}
		else
		{				
			$decodedData = substr($bytes, 2);		
		}		
	}

    var_dump($decodedData);
        
        $data = json_decode($decodedData);

        switch (isset($data->type) ? $data->type : null) {

            // add to group or disconnect
            case 'new_user':

                isset($data->group_id) ?
                                $client->setGroup($data->group_id) :
                                $client->onDisconnect();
                return;
                break;

            case 'close':
                $client->onDisconnect();
                return;
                break;

            default:
                break;
        }

        // to current socket
//        $client->send($data->comment);
        //to all server sockets
//        $client->sendServer($data->comment);
        //to all server sockets exclude sender
        $client->broadcastServer($data->comment);
        
        //Group
//        $client->sendGroup($data->comment);
//        $client->broadcastGroup($data->comment);
//        $client->getConnectionsByGroupKey(1);
        
        //Application
//        $this->sendApp($data->comment);
//        $this->broadcastApp($client, $data->comment);
        
    }

}