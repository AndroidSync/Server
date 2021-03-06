<?php

namespace Zync\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Zync\Http\Controllers\Controller;
use Zync\Helpers\Enums\ApiError;
use Zync\Kinds\User;
use Zync\Kinds\Clipboard;
use \Zync\Helpers\Utils;

class ClipboardController extends Controller {

	private static $REQUIRED_DATA = [
		'timestamp' => 'integer',
		'hash' => [
			'crc32' => 'string',
		],
		'encryption' => [
			'type' => 'AES256-GCM-NOPADDING',
			'iv' => 'string',
			'salt' => 'string'
		],
		'payload' => 'string',
		'payload-type' => 'TEXT|IMAGE|VIDEO|BINARY'
	];

	public function getClipboard(Request $request) {
		$user = User::getFromHeaderToken();
		$clipboard = $user->getClipboard();

		if(is_null($clipboard)){
			return response()->json(ApiError::$CLIPBOARD_EMPTY, 200);
		}

		return [
			"success" => true,
			"data" => $clipboard->getLastClipboard()
		];
	}

	public function getClipboardWithTimestamp(Request $request, $timestamp) {
		$user = User::getFromHeaderToken();
		$clipboard = $user->getClipboard();

		if(is_null($clipboard)){
			return response()->json(ApiError::$CLIPBOARD_EMPTY, 200);
		}

		if (strpos($timestamp, ',') !== false) {
			$contents = $clipboard->getManyTimestampClipboards(explode(",", $timestamp));

			if(is_null($contents)){
				return response()->json(ApiError::$CLIPBOARDS_NOT_FOUND, 404);
			}

			return [
				"success" => true,
				"data" => [
					"clipboards" => $contents
				]
			];
		}

		$contents = $clipboard->getTimestampClipboard($timestamp);

		if(is_null($contents)){
			return response()->json(ApiError::$CLIPBOARD_NOT_FOUND, 404);
		}

		return [
			"success" => true,
			"data" => $contents
		];
	}

	public function postClipboard(Request $request) {
		$user = User::getFromHeaderToken();
		$clipboard = $user->getClipboard();

		$data = $request->json("data");

		if(is_null($data)){
			return response()->json(ApiError::$CLIPBOARD_INVALID, 400);
		}

		$difference = Utils::array_diff_key_recursive(self::$REQUIRED_DATA, $data);
		if(!is_null($difference)){
			$response = ApiError::$CLIPBOARD_INVALID;
			$response["error"]["missing"] = $difference;
			return response()->json($response, 400);
		}

		$validation = Utils::array_validate_data_types(self::$REQUIRED_DATA, $data);
		if(!is_null($validation)){
			$response = ApiError::$CLIPBOARD_INVALID;
			$response["error"]["invalid"] = $validation;
			return response()->json($response, 400);
		}

		$size = mb_strlen($data["payload"]);
		if($size > 10000000 && $data["timestamp"] < Utils::time_milliseconds() - Clipboard::EXPIRY_TIME_MAX){
			return response()->json(ApiError::$CLIPBOARD_LATE, 400);
		}else if($size < 10000000 && $data["timestamp"] < Utils::time_milliseconds() - Clipboard::EXPIRY_TIME_MIN){
			return response()->json(ApiError::$CLIPBOARD_LATE, 400);
		}

		if($data["timestamp"] > Utils::time_milliseconds()){
			return response()->json(ApiError::$CLIPBOARD_TIME_TRAVEL, 400);
		}

		if(!is_null($clipboard)){
			if($data["timestamp"] < $clipboard->getData()["timestamp"]){
				return response()->json(ApiError::$CLIPBOARD_OUTDATED, 400);
			}

			if($clipboard->exists($data["hash"]["crc32"])){
				return response()->json(ApiError::$CLIPBOARD_IDENTICAL, 400);
			}

			$clipboard->newClip($data);
			$clipboard->saveContents($data["payload"], $data["timestamp"]);
			$clipboard->save();
		}else{
			$clipboard = Clipboard::create($user->getData()->key()->pathEndIdentifier(), $data);
			$clipboard->saveContents($data["payload"], $data["timestamp"]);
		}

		return response()->json(["success" => true]);
	}

	public function getHistory(Request $request) {
		$user = User::getFromHeaderToken();
		$clipboard = $user->getClipboard();

		if(is_null($clipboard)){
			return [
				"success" => true,
				"data" => [
					"history" => []
				]
			];
		}

		return [
			"success" => true,
			"data" => [
				"history" => $clipboard->getHistory()
			]
		];
	}

}
