<?php
use Workerman\Worker;

$monitor_dir = realpath(__DIR__.'/..');
$worker = new Worker();
$worker->name = 'FileMonitor';
$worker->reloadable = false;
$monitor_files = array();

$worker->onWorkerStart = function($worker)
{
    if(!extension_loaded('inotify'))
    {
        echo "FileMonitor : Please install inotify extension.\n";
        return;
    }

    global $monitor_dir, $monitor_files;
    $worker->inotifyFd = inotify_init();
    stream_set_blocking($worker->inotifyFd, 0);
    $dir_iterator = new RecursiveDirectoryIterator($monitor_dir);
    $iterator = new RecursiveIteratorIterator($dir_iterator);
    foreach ($iterator as $file)
    {
        if(pathinfo($file, PATHINFO_EXTENSION) != 'php')
        {
            continue;
        }
        $wd = inotify_add_watch($worker->inotifyFd, $file, IN_MODIFY);
        $monitor_files[$wd] = $file;
    }
    Worker::$globalEvent->onReadable($worker->inotifyFd, 'check_files_change');

};

function check_files_change($inotify_fd)
{
    global $monitor_files;
    $events = inotify_read($inotify_fd);
    if($events)
    {
        foreach($events as $ev)
        {
            $file = $monitor_files[$ev['wd']];
            echo $file ." update and reload\n";
            unset($monitor_files[$ev['wd']]);
            $wd = inotify_add_watch($inotify_fd, $file, IN_MODIFY);
            $monitor_files[$wd] = $file;
        }
        posix_kill(posix_getppid(), SIGUSR1);
    }
}
