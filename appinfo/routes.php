<?php

/**
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Stefan Klemm <mail@stefan-klemm.de>
 * @copyright (c) 2014, Stefan Klemm
 */

namespace OCA\Recognize\AppInfo;

/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */
return [
	'routes' => [
		//internal ADMIN API
		['name' => 'admin#reset', 'url' => '/admin/reset', 'verb' => 'GET'],
		['name' => 'admin#clearAllJobs', 'url' => '/admin/clearJobs', 'verb' => 'GET'],
		['name' => 'admin#recrawl', 'url' => '/admin/recrawl', 'verb' => 'GET'],
		['name' => 'admin#reset_faces', 'url' => '/admin/resetFaces', 'verb' => 'GET'],
		['name' => 'admin#count', 'url' => '/admin/count', 'verb' => 'GET'],
		['name' => 'admin#countQueued', 'url' => '/admin/countQueued', 'verb' => 'GET'],
		['name' => 'admin#count_missed', 'url' => '/admin/countMissed', 'verb' => 'GET'],
		['name' => 'admin#avx', 'url' => '/admin/avx', 'verb' => 'GET'],
		['name' => 'admin#platform', 'url' => '/admin/platform', 'verb' => 'GET'],
		['name' => 'admin#musl', 'url' => '/admin/musl', 'verb' => 'GET'],
		['name' => 'admin#nice', 'url' => '/admin/nice', 'verb' => 'GET'],
		['name' => 'admin#ffmpeg', 'url' => '/admin/ffmpeg', 'verb' => 'GET'],
		['name' => 'admin#nodejs', 'url' => '/admin/nodejs', 'verb' => 'GET'],
		['name' => 'admin#libtensorflow', 'url' => '/admin/libtensorflow', 'verb' => 'GET'],
		['name' => 'admin#wasmtensorflow', 'url' => '/admin/wasmtensorflow', 'verb' => 'GET'],
		['name' => 'admin#gputensorflow', 'url' => '/admin/gputensorflow', 'verb' => 'GET'],
		['name' => 'admin#cron', 'url' => '/admin/cron', 'verb' => 'GET'],
		['name' => 'admin#hasJobs', 'url' => '/admin/jobs/{task}', 'verb' => 'GET'],
		['name' => 'admin#get_setting', 'url' => '/admin/settings/{setting}', 'verb' => 'GET'],
		['name' => 'admin#set_setting', 'url' => '/admin/settings/{setting}', 'verb' => 'PUT'],
		['name' => 'admin#errors', 'url' => '/admin/errors', 'verb' => 'GET'],
		['name' => 'admin#clearErrors', 'url' => '/admin/errors', 'verb' => 'DELETE'],
		['name' => 'admin#tensorflowStatus', 'url' => '/admin/tensorflow', 'verb' => 'GET'],
		['name' => 'admin#installDeps', 'url' => '/admin/installDeps', 'verb' => 'POST'],
		['name' => 'admin#mergeSuggestions', 'url' => '/admin/faces/mergeSuggestions', 'verb' => 'GET'],
		['name' => 'admin#autoMerge', 'url' => '/admin/faces/autoMerge', 'verb' => 'POST'],
		['name' => 'admin#faceBackendStatus', 'url' => '/admin/faces/backend', 'verb' => 'GET'],
		['name' => 'admin#faceProgress', 'url' => '/admin/faces/progress', 'verb' => 'GET'],
		['name' => 'admin#switchFaceBackend', 'url' => '/admin/faces/backend', 'verb' => 'POST'],
		['name' => 'admin#installInsightface', 'url' => '/admin/faces/installInsightface', 'verb' => 'POST'],
		['name' => 'admin#clipStatus', 'url' => '/admin/clip', 'verb' => 'GET'],
		['name' => 'admin#installClip', 'url' => '/admin/clip/install', 'verb' => 'POST'],
		['name' => 'admin#clipTest', 'url' => '/admin/clip/test', 'verb' => 'GET'],
		['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
		['name' => 'search#score', 'url' => '/api/search/score', 'verb' => 'POST'],
		['name' => 'similar#groups', 'url' => '/api/similar', 'verb' => 'GET'],
		['name' => 'admin#forkUpdates', 'url' => '/admin/updates', 'verb' => 'GET'],
		['name' => 'admin#forkUpdate', 'url' => '/admin/updates/{app}', 'verb' => 'POST'],
		// user API (Memories / Photos person pages)
		['name' => 'faces#findMore', 'url' => '/api/faces/{clusterId}/find', 'verb' => 'POST'],
		// manual tagging / review (Memories "People in this photo", "Review unnamed people")
		['name' => 'faces#fileFaces', 'url' => '/api/files/{fileId}/faces', 'verb' => 'GET'],
		['name' => 'faces#addFace', 'url' => '/api/files/{fileId}/faces', 'verb' => 'POST'],
		['name' => 'faces#unignoreFile', 'url' => '/api/files/{fileId}/faces/unignore', 'verb' => 'POST'],
		['name' => 'faces#assign', 'url' => '/api/faces/detections/{detectionId}/assign', 'verb' => 'POST'],
		['name' => 'faces#detach', 'url' => '/api/faces/detections/{detectionId}/detach', 'verb' => 'POST'],
		['name' => 'faces#ignoreDetection', 'url' => '/api/faces/detections/{detectionId}/ignore', 'verb' => 'POST'],
		['name' => 'faces#review', 'url' => '/api/faces/review', 'verb' => 'GET'],
		['name' => 'faces#progress', 'url' => '/api/faces/progress', 'verb' => 'GET'],
		['name' => 'faces#track', 'url' => '/api/faces/track', 'verb' => 'POST'],
		['name' => 'faces#rename', 'url' => '/api/faces/{clusterId}', 'verb' => 'PUT'],
		['name' => 'faces#mergeInto', 'url' => '/api/faces/{clusterId}/merge/{targetId}', 'verb' => 'POST'],
		['name' => 'faces#ignoreCluster', 'url' => '/api/faces/{clusterId}/ignore', 'verb' => 'POST'],
	],
];
