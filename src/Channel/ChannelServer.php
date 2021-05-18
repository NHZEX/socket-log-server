<?php

namespace App\Channel;

use Channel\Server;
use Workerman\Protocols\Frame;
use Workerman\Worker;
use function sprintf;

class ChannelServer extends Server
{
    public function __construct($name = 'default')
    {
        // unix:///tmp/my_file
        $worker = new Worker(sprintf('unix://%s/%s.sock', RUNNING_TMP_ID, $name));
        $worker->protocol = Frame::class;
        $worker->count = 1;
        $worker->name = 'ChannelServer';
        $worker->channels = array();
        $worker->onMessage = array($this, 'onMessage') ;
        $worker->onClose = array($this, 'onClose');
        $this->_worker = $worker;
    }
}
