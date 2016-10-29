<?php

set_time_limit(0);
@ob_end_flush();
ob_implicit_flush(true);

include_once(dirname(__DIR__).'/vendor/autoload.php');

$serialPort = '/dev/ttyAMA0';
if (!file_exists($serialPort)) {
	$serialPort = '/dev/ttyUSB0';
}

`stty -F $serialPort cs8 115200 ignbrk -brkint -imaxbel -opost -onlcr -isig -icanon -iexten -echo -echoe -echok -echoctl -echoke noflsh -ixon -crtscts`;

@$serialHandle = fopen($serialPort, "w+", false);
if (!$serialHandle) { 
	echo "ERROR: cannot open serial port $serialPort for reading and writing.\n";
}

Amp\run(function () use ($serialHandle) {
	$outbuffer = '';
	$settled   = FALSE;
	$client = new Amp\Beanstalk\BeanstalkClient('172.17.0.12:11300');

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
			echo "writable: ";
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
				echo "RESERVED JOB: ".$result[0]."\n";
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
					echo "DELETING JOB " . $id."\n";
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
		echo($buffer);
		$obj = json_decode($buffer);
		if (! $obj ) {
			$buffer = '';
		} else{
			$bnstk->useTube($obj->type)->when(function($err, $rslt) use ($obj, $bnstk, $buffer) {
				$bnstk->put($buffer);
			});
		}
	}
}