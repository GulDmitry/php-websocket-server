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
        $data = json_decode($jData);

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
        $client->send($data->comment);
        //to all server sockets
        $client->sendServer($data->comment);
        //to all server sockets exclude sender
        $client->broadcastServer($data->comment);
        
        //Group
        $client->sendGroup($data->comment);
        $client->broadcastGroup($data->comment);
        $client->getConnectionsByGroupKey(1);
        
        //Application
        $this->sendApp($data->comment);
        $this->broadcastApp($client, $data->comment);
        

    }

}