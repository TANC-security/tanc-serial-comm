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
	sleep (30);
	exit();
}

`stty -F $serialPort cs8 115200 ignbrk -brkint -imaxbel -opost -onlcr -isig -icanon -iexten -echo -echoe -echok -echoctl -echoke noflsh -ixon -crtscts`;

@$serialHandle = fopen($serialPort, "w+", false);
if (!$serialHandle) { 
	echo "E/Serial: cannot open serial port $serialPort for reading and writing.\n";
}

Amp\run(function () use ($serialHandle) {
	$outbuffer = '';
	$settled   = FALSE;
	$client = new Amp\Beanstalk\BeanstalkClient('127.0.0.1:11300');

/*
	$prom1 = $client->useTube('error');
	$prom2 = $client->put('big ol error');

	$prom1 = $client->useTube('input');
	$prom2 = $client->put('12341');
	*/

	Amp\onReadable($serialHandle, function($watcherId, $handle) use ($client) {
		$data = fgets($handle, 4096);


		if ($data == '') {
			if ( !is_resource($handle) || @feof($handle) ) {
				Amp\cancel($watcherId);
			}
		} else {
			incomingSerialData($data, $client);
		}
	});
	$writeWatcher = Amp\onWritable($serialHandle, function($watcherId, $handle) use(&$outbuffer, &$settled) {
		if (strlen($outbuffer) && $settled) {
			echo "D/Output: ";
			echo($outbuffer)."\n";
			fputs($handle, $outbuffer{0});
			fflush($handle);
			usleep(200);
			$outbuffer = substr($outbuffer, 1);
		} else {
			Amp\disable($watcherId);
		}
	});
	Amp\disable($writeWatcher);
	Amp\once(function() use ($writeWatcher, &$settled) {
		$settled = TRUE;
		Amp\enable($writeWatcher);
	}, $msDelay = 4000);

	$client->watch('input');
	Amp\repeat(function() use ($client, &$outbuffer, $writeWatcher){

		try {
			$promise = $client->reserve(0);
		} catch (Exception $e) {
			if ($e instanceOf Amp\Beanstalk\DeadlineSoonException) {
				var_dump($e->getJob());
			}
		}
		$promise->when( function($error, $result, $cbData) use ($client, &$outbuffer, $writeWatcher) {
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
			$outbuffer .= $result[1];
			Amp\enable($writeWatcher);
			try {
				$id = $result[0];
				$k  = $client->delete($result[0]);
				$k->when( function($err, $res) use ($client, $id) {
					echo "I/Job: DELETING JOB: " . $id."\n";
					var_dump($err);
					var_dump($res);
				});
			} catch (Exception $e) {
				var_dump($e->getMessage());
			}
		});
	}, $msInterval=50);

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
			if ($obj->type == 'display') {
				//don't pile up jobs for display, write to tmpfs/shm
				$tmpf = fopen('/dev/shm/display.json', 'w+');
				if ($tmpf) {
					fputs ($tmpf, $buffer);
					fclose($tmpf);
				}
			} else {
				$bnstk->useTube($obj->type)->when(function($err, $rslt) use ($obj, $bnstk, $buffer) {
					echo("D/Job: use tube " .$obj->type."\n");
					echo("D/Job: put " .$buffer."\n");
					$bnstk->put($buffer, 15, 0);
				});
			}
		}
		$buffer = '';
		echo("D/Buffer: clear\n");
	}
}
