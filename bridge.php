#!/usr/bin/env php
<?php
/* Author: Romain "Artefact2" Dal Maso <romain.dalmaso@artefact2.com>
 *
 * This program is free software. It comes without any warranty, to the
 * extent permitted by applicable law. You can redistribute it and/or
 * modify it under the terms of the Do What The Fuck You Want To Public
 * License, Version 2, as published by Sam Hocevar. See
 * http://sam.zoy.org/wtfpl/COPYING for more details. */

function starts_with(string &$s, string $start): bool {
	$len = strlen($start);
	if(substr($s, 0, $len) !== $start) return false;
	$s = substr($s, $len);
	return true;
}

function parse_dbus_object(string &$object) {
	$object = ltrim($object);
	if(starts_with($object, "method return ")) {
		return [
			"method return",
			parse_dbus_object(explode("\n", $object, 2)[1])
		];
	} else if(starts_with($object, "array [")) {
		$arr = [];
		$object = ltrim($object);
		while($object[0] !== "]") {
			$entry = parse_dbus_object($object);
			if($entry === false) {
				return false;
			}
			if($entry[0] === "dict entry") {
				$arr[$entry[1]] = $entry[2];
			} else {
				$arr[] = $entry;
			}
			$object = ltrim($object);
		}
		$object = substr($object, 1);
		return [ "array", $arr ];
	} else if(starts_with($object, "dict entry(")) {
		$key = parse_dbus_object($object);
		$value = parse_dbus_object($object);
		if($key === false || $value === false) return false;
		$object = ltrim($object);
		if($object[0] !== ")") return false;
		$object = substr($object, 1);
		if($key[0] !== "string") return false;
		return [ "dict entry", $key[1], $value ];
	} else if(starts_with($object, "variant ")) {
		return parse_dbus_object($object);
	} else if(starts_with($object, "string \"")
	          || starts_with($object, "object path \"")) {
		list($string, $object) = explode("\"", $object, 2);
		return [ "string", $string ];
	} else if(starts_with($object, "double ")) {
		list($val, $object) = explode(" ", $object, 2);
		return [ "double", floatval($val) ];
	} else if(starts_with($object, "boolean ")) {
		list($val, $object) = explode(" ", $object, 2);
		return [ "boolean", $val === "true" ];
	} else if(starts_with($object, "int64 ")) {
		list($val, $object) = explode(" ", $object, 2);
		return [ "int64", intval($val) ];
	}

	else {
		var_dump($object);
		return false;
	}
}

$rpc = false;

while(true) {
	/* XXX: don't hardcode mpv */
	$out = shell_exec("dbus-send --session --dest=org.mpris.MediaPlayer2.mpv --type=method_call --print-reply /org/mpris/MediaPlayer2 org.freedesktop.DBus.Properties.GetAll string:org.mpris.MediaPlayer2.Player 2>/dev/null");
	if($out === false) die(1);

	if($out !== null) {
		$out = parse_dbus_object($out)[1][1] ?? null;
	}

	$title = $out["Metadata"][1]["xesam:title"][1] ?? null;
	$artist = $out["Metadata"][1]["xesam:artist"][1][0][1] ?? null;
	// XXX: handle album art
	//$art = $out["Metadata"][1]["xesam:artUrl"][1] ?? null;
	$length = $out["Metadata"][1]["mpris:length"][1] ?? null;
	$position = $out["Position"][1] ?? null;

	if(($out["PlaybackStatus"][1] ?? null) !== "Playing"
	   || $title === null) {
		if($rpc !== false) {
			fclose($rpc);
			$rpc = false;
			goto end;
		}
	}

	if($rpc === false) {
		/* XXX: don't hardcode this */
		$rpc = fsockopen("unix:///run/user/1000/discord-ipc-0", -1,
		                 $errno, $errstr, 1);
		if($rpc === false) goto end;
		$payload = json_encode([
			'v' => 1,
			'client_id' => "1453411490357838045",
		]);
		fwrite($rpc, pack('VV', 0, strlen($payload)).$payload);
		$rep = fread($rpc, 4096);
		$json = json_decode(substr($rep, 8), true);
		if(($json["evt"] ?? null) !== "READY") {
			fclose($rpc);
			$rpc = false;
			var_dump($json);
			goto end;
		}
	}

	// https://discord.com/developers/docs/events/gateway-events#activity-object
	$payload = [
		'nonce' => time(),
		'cmd' => 'SET_ACTIVITY',
		'args' => [
			'pid' => getmypid(),
			'activity' => [
				'type' => 2,
				'status_display_type' => 1,
				'state' => $artist ?? $title,
				'details' => $title,
			],
		],
	];
	if($length > 0 && $position > 0) {
		$t = time();
		$start = (int)($t - $position / 1000000.0);
		$end = (int)($start + $length / 1000000.0);
		$payload['args']['activity']['timestamps'] = [
			'start' => $start,
			'end' => $end,
		];
	}
	$payload = json_encode($payload);
	fwrite($rpc, pack('VV', 1, strlen($payload)).$payload);
	$rep = fread($rpc, 4096);
	$json = json_decode(substr($rep, 8), true);
	if(($json["cmd"] ?? null) !== "SET_ACTIVITY") {
		fclose($rpc);
		$rpc = false;
		var_dump($json);
		goto end;
	}

	end:
	sleep(20);
}
