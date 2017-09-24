<?php

set_time_limit(0);
@ob_end_flush();
ob_implicit_flush(true);

include_once(dirname(__DIR__).'/serial-comm/vendor/autoload.php');

$amaPort = '/dev/ttyAMA';
$usbPort = '/dev/ttyUSB';
$serialPort = FALSE;
for ($x =0; $x <=10; $x++) {
	if (file_exists($amaPort.$x)) {
		$serialPort = $amaPort.$x;
		break;
	}
	if (file_exists($usbPort.$x)) {
		$serialPort = $usbPort.$x;
		break;
	}
}
if (!$serialPort) {
	echo "E/Serial: No serial port found at /dev/ttyAMA* nor /dev/ttyUSB*\n";
	sleep (20);
	exit();
}

`stty -F $serialPort cs8 115200 ignbrk -brkint -imaxbel -opost -onlcr -isig -icanon -iexten -echo -echoe -echok -echoctl -echoke noflsh -ixon -crtscts`;

@$serialHandle = fopen($serialPort, "w+", false);
if (!$serialHandle) { 
	echo "E/Serial: cannot open serial port $serialPort for reading and writing.\n";
	sleep(20);
	exit();
}

$beanstalkAddress = getenv('BEANSTALK_ADDRESS');
if ($beanstalkAddress == '') {
        $beanstalkAddress = '127.0.0.1:11300';
}

$beanstalkAddress = 'tcp://'.$beanstalkAddress.'?tube=display';

$client = new Amp\Beanstalk\BeanstalkClient($beanstalkAddress);


class LocalBeanstalkClient extends \Amp\Beanstalk\BeanstalkClient { 

    public function statsJob(int $id): \Amp\Promise {
        $payload = "stats-job $id\r\n";
        return $this->mysend($payload, function (array $response) {
            list($type) = $response;

            var_dump($response);
            switch ($type) { 
                case "OK":
                    return $response[1];

                case "NOT_FOUND":
                    return false;

                default:
                    throw new BeanstalkException("Unknown response: " . $type);
            }
        });
    } 

    protected function mysend(string $message, callable $transform = null): \Amp\Promise { 
        return \Amp\call(function () use ($message, $transform) { 
            $this->deferreds[] = $deferred = new \Amp\Deferred;
            $promise = $deferred->promise();

            yield $this->connection->send($message);
            $response = yield $promise;

            return $transform ? $transform($response) : $response;
        });
    } 
}

use Amp\Loop;

Loop::run(function () use ($serialHandle, $client, $beanstalkAddress) {
	$outbuffer = '';
	$settled   = FALSE;

	Loop::onReadable($serialHandle, function($watcherId, $handle) use ($client) {
		$data = fgets($handle, 4096);


		if ($data == '') {
			if ( !is_resource($handle) || @feof($handle) ) {
				Loop::cancel($watcherId);
			}
		} else {
			incomingSerialData($data, $client);
		}
	});

	$writeWatcher = Loop::onWritable($serialHandle, function($watcherId, $handle) use(&$outbuffer, &$settled) {
		if (strlen($outbuffer) && $settled) {
			echo "D/Output: ";
			echo($outbuffer)."\n";
			fputs($handle, $outbuffer{0});
			fflush($handle);
			usleep(200);
			$outbuffer = substr($outbuffer, 1);
		} else {
			Loop::disable($watcherId);
		}
	});
	Loop::disable($writeWatcher);
	Loop::delay($msDelay=4000,
	    function() use ($writeWatcher, &$settled) {
		$settled = TRUE;
		Loop::enable($writeWatcher);
	});

	$client->watch('input');
	Loop::repeat(
	    $msInterval=50,
	    function() use ($client, &$outbuffer, $writeWatcher){

		try {
				$promise = $client->reserve(0);
				$promise->onResolve( function($error, $result) use ($client, &$outbuffer, $writeWatcher) {
					if ($error instanceOf Amp\Beanstalk\TimedOutException) {
						return;
					}
					if ($error instanceOf Amp\Beanstalk\DeadlineSoonException) {
						var_dump( get_class($error) );
						return;
					}
					if ($result) {
						echo "I/Job: RESERVED JOB: ".$result[0]."\n";
					}

					if (!$result) {
						return;
					}
					var_dump($result[1]);
					$outbuffer .= $result[1];
					Loop::enable($writeWatcher);
					try {
						$id = $result[0];
						$k  = $client->delete($result[0]);
						$k->onResolve( function($err, $res) use ($client, $id) {
							echo "I/Job: DELETING JOB: " . $id."\n";
							var_dump($err);
							var_dump($res);
						});
					} catch (Exception $e) {
						var_dump($e->getMessage());
					}
				});
	//		});
		} catch (Exception $e) {
			if ($e instanceOf Amp\Beanstalk\DeadlineSoonException) {
				var_dump($e->getJob());
			}
		}

	});

	//clean up stale display messages
	//so that only display messages less than 5 seconds old are available
	//TODO: add statsJob to official beanstalkClient
	Loop::delay($msInterval=10000,
		function() use ($beanstalkAddress){
		echo("D/Cleanup display queue\n");

		//we need a new client beause reserve checks all watched tubes
		//and we don't want to get into locking one tube vs another
		$client = new \LocalBeanstalkClient($beanstalkAddress);
		$client->watch("display")->onResolve(function($err, $rslt) use ($client) {
			echo "D/Queue watching display bucket.\n";
			$lastid=0;
			Loop::repeat(
			  $msInterval=1000,
			  function() use ($client, $lastid){
				$client->reserve(0)->onResolve( function($error, $result) use ($client, &$lastid) {
				$info = $client->statsJob($result[0]);
				$info->onResolve(function($error, $result) use($client) {
					$lines = explode("\n", $result);
					array_shift($lines);

					$stats = parse_ini_string( str_replace(": ", "=", implode("\n", $lines)) ); 
					if (empty($stats)) {
						return;
					}
					if (intval($stats['age']) > 5) {
						$k  = $client->delete($stats['id']);
						echo "D/cleanup deleted stale job ".$stats['id']."\n";
					}
				});
			});
			});
		});
	});
});

function incomingSerialData($d, $bnstk) {
	static $buffer = '';
	if (!trim($d)) {
		//blank newline
		$buffer = '';
		return;
	}

	$buffer .= $d;
	if (strpos($buffer, "\n") !== FALSE) {
		echo("D/Buffer: ".$buffer);
		$obj = json_decode($buffer);
		if (! $obj ) {
			$buffer = '';
		} else{
			/*
			if ($obj->type == 'display') {
				//don't pile up jobs for display, write to tmpfs/shm
				$tmpf = fopen('/dev/shm/display.json', 'w+');
				if ($tmpf) {
					fputs ($tmpf, $buffer);
					fclose($tmpf);
				}
			} else {
			 */
				$bnstk->use($obj->type)->onResolve(function($err, $rslt) use ($obj, $bnstk, $buffer) {
					echo("D/Job: use tube " .$obj->type."\n");
					echo("D/Job: put " .$buffer."\n");
					$bnstk->put($buffer, 15, 0, 10)->onResolve(function($err, $rslt) {
if ($err) {
					echo("E/Job: PUT HAD ERRORS \n");
echo $err."\n";
}
					echo("D/Job: put is done \n");
					});
				});
//			}
		}
		$buffer = '';
		echo("D/Buffer: clear\n");
	}
}


