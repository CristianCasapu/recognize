<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Controller;

use OCA\Recognize\Db\FaceClusterMapper;
use OCA\Recognize\Service\FaceSearchService;
use OCA\Recognize\Service\FaceTagging;
use OCA\Recognize\Service\FaceTracker;
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
		private FaceTagging $tagging,
		private FaceTracker $tracker,
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

	/** Live face-scan progress (People page banner). */
	#[NoAdminRequired]
	public function progress(): JSONResponse {
		return $this->guard(fn () => \OCP\Server::get(\OCA\Recognize\Service\FaceProgress::class)->status());
	}

	/** Faces in a photo, with the person each one is assigned to. */
	#[NoAdminRequired]
	public function fileFaces(int $fileId): JSONResponse {
		return $this->guard(fn (string $uid) => ['faces' => $this->tagging->listFaces($uid, $fileId)]);
	}

	#[NoAdminRequired]
	public function unignoreFile(int $fileId): JSONResponse {
		return $this->guard(fn (string $uid) => ['restored' => $this->tagging->unignoreFile($uid, $fileId)]);
	}

	/** Assign a face to a person (existing `cluster_id`, or `title`: an existing name or a new person). */
	#[NoAdminRequired]
	public function assign(int $detectionId, ?int $cluster_id = null, ?string $title = null): JSONResponse {
		return $this->guard(fn (string $uid) => $this->tagging->assign($uid, $detectionId, $cluster_id, $title));
	}

	#[NoAdminRequired]
	public function detach(int $detectionId): JSONResponse {
		return $this->guard(function (string $uid) use ($detectionId) {
			$this->tagging->detach($uid, $detectionId);
			return ['ok' => true];
		});
	}

	#[NoAdminRequired]
	public function ignoreDetection(int $detectionId): JSONResponse {
		return $this->guard(function (string $uid) use ($detectionId) {
			$this->tagging->ignoreDetection($uid, $detectionId);
			return ['ok' => true];
		});
	}

	/** Unnamed people (biggest first) with sample faces and name suggestions. */
	#[NoAdminRequired]
	public function review(int $limit = 200): JSONResponse {
		return $this->guard(fn (string $uid) => $this->tagging->review($uid, max(1, min(1000, $limit))));
	}

	#[NoAdminRequired]
	public function rename(int $clusterId, string $title = ''): JSONResponse {
		return $this->guard(fn (string $uid) => $this->tagging->rename($uid, $clusterId, $title));
	}

	#[NoAdminRequired]
	public function mergeInto(int $clusterId, int $targetId): JSONResponse {
		return $this->guard(fn (string $uid) => ['moved' => $this->tagging->mergeInto($uid, $clusterId, $targetId)]);
	}

	#[NoAdminRequired]
	public function ignoreCluster(int $clusterId): JSONResponse {
		return $this->guard(fn (string $uid) => ['ignored' => $this->tagging->ignoreCluster($uid, $clusterId)]);
	}

	/** Propagate known people to unassigned faces in neighbouring frames of bursts. */
	#[NoAdminRequired]
	public function track(): JSONResponse {
		return $this->guard(fn (string $uid) => ['assigned' => $this->tracker->track($uid)]);
	}

	/** @param callable(string):array $fn */
	private function guard(callable $fn): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse($fn($user->getUID()));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['message' => $e->getMessage() ?: 'Not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
