<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Controller;

use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Service\FaceSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * User facing API used by the Memories / Photos person pages.
 */
final class FacesController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private FaceClusterMapper $clusterMapper,
		private FaceSearchService $faceSearch,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Assign every sufficiently similar unassigned / unnamed face to this cluster,
	 * optionally only within a folder (path relative to the user's files).
	 */
	#[NoAdminRequired]
	public function findMore(int $clusterId, ?string $folder = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$cluster = $this->clusterMapper->find($clusterId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => 'Unknown person'], Http::STATUS_NOT_FOUND);
		}
		if ($cluster->getUserId() !== $user->getUID()) {
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}
		try {
			$result = $this->faceSearch->findMore($user->getUID(), $cluster, $folder !== null && trim($folder) !== '' ? trim($folder) : null);
		} catch (\OCP\Files\NotFoundException $e) {
			return new JSONResponse(['message' => 'Folder not found'], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse($result);
	}
}
