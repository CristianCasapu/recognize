<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Controller;

use OCA\Recognize\Service\SemanticSearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Natural-language photo search for the current user.
 */
final class SearchController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private SemanticSearch $search,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse {available: bool, results: [{fileid, score}]}
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function search(string $q = '', int $limit = 200): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		if (!$this->search->isAvailable()) {
			return new JSONResponse(['available' => false, 'results' => [], 'message' => 'Natural-language search is not set up (Recognize admin settings)'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		try {
			$results = $this->search->searchForUser($user->getUID(), $q, max(1, min($limit, 2000)));
		} catch (\Throwable $e) {
			return new JSONResponse(['available' => true, 'results' => [], 'message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$out = [];
		foreach ($results as $fileId => $score) {
			$out[] = ['fileid' => $fileId, 'score' => round($score, 4)];
		}
		return new JSONResponse(['available' => true, 'results' => $out]);
	}
}
