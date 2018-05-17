<?php

set_time_limit(0);
@ob_end_flush();
ob_implicit_flush(true);

include_once(dirname(__DIR__).'/serial-comm/vendor/autoload.php');



$mqttAddress = getenv('MQTT_ADDRESS');
if ($mqttAddress == '') {
	$mqttAddress = '127.0.0.1:1883';
}

$topicPrefix = getenv('TOPIC_PREFIX');
if ($topicPrefix == '') {
	$topicPrefix = 'security/';
}

$mqttAddress = 'tcp://'.$mqttAddress.'?topics=security/input';

$client = new \MarkKimsal\Mqtt\Client($mqttAddress);
$client->connect();

$serialHandle = openSerialPort();

use Amp\Loop;

Loop::run(function () use ($serialHandle, &$client, $mqttAddress, $topicPrefix) {
	$outbuffer = '';
	$settled   = FALSE;

	Loop::onReadable($serialHandle, function($watcherId, $handle) use (&$client, $topicPrefix) {
		$data = fgets($handle, 4096);


		if ($data == '') {
			if ( !is_resource($handle) || @feof($handle) ) {
				Loop::cancel($watcherId);
			}
		} else {
			incomingSerialData($data, $topicPrefix, $client, $watcherId);
		}
	});

	$writeWatcher = Loop::onWritable($serialHandle, function($watcherId, $handle) use(&$outbuffer, &$settled) {
		if (strlen($outbuffer) && $settled) {
//			echo "D/Output: ";
//			echo($outbuffer)."\n";
			fputs($handle, $outbuffer{0});
			fflush($handle);
			usleep(200);
			$outbuffer = substr($outbuffer, 1);
		} else {
			Loop::disable($watcherId);
			//should exit so watchdog can restart
		}
	});

	Loop::disable($writeWatcher);
	Loop::delay($msDelay=2000,
	    function() use ($writeWatcher, &$settled) {
		$settled = TRUE;
		Loop::enable($writeWatcher);
	});

	$client->on('close', function($error, $result) use ($client, &$outbuffer, $writeWatcher){
		echo "close\n";
		var_dump(
			$error
		);
	});
	$client->on('error', function($error, $result) use ($client, &$outbuffer, $writeWatcher){
		echo "error\n";
		var_dump(
			$error
		);
	});


	$client->on('message', function($packet) use ($client, &$outbuffer, $writeWatcher){
		try {
			$result = $packet->getMessage();
			if (!strlen($result)) {
				return;
			}
			$outbuffer .= $result;
			Loop::enable($writeWatcher);
			try {
				$id = $result[0];
			} catch (Exception $e) {
				var_dump($e->getMessage());
			}
		} catch (Exception $e) {
			if ($e instanceOf Amp\Beanstalk\DeadlineSoonException) {
				var_dump($e->getJob());
			}
		}

	});
});

function incomingSerialData($d, $topicPrefix, $client, &$watcherId) {
	static $buffer = '';
	if (!trim($d)) {
		//blank newline
		return;
	}

	$buffer .= $d;
	if (strpos($buffer, "\n") === FALSE) {
		return;
	}
	echo("D/Buffer: ".$buffer);
	$obj = json_decode(trim($buffer));

	if (! $obj ) {
		echo( "E/Buffer: not valid json - ".$buffer."\n");
		$buffer = '';
		return;
	}

	if (! is_object($obj)) {
		$buffer = '';
		return;
	}

	$buffer = '';

	$topic = $topicPrefix . $obj->type;
	//we don't want anymore data buffered until the tube is
	//changed and the put is done
	Loop::disable($watcherId);
	if ($obj->type == 'display') {
		$client->publishRetain( json_encode($obj), $topic, 0, function($error, $result) use($watcherId) {
			Loop::enable($watcherId);
		});
	} else {
		$client->publish( json_encode($obj), $topic, 0, function($error, $result) use($watcherId) {
			Loop::enable($watcherId);
		});
	}
}


function openSerialPort() {
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
	return $serialHandle;
}
