<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Controller;

use OCA\Recognize\Service\SimilarPhotos;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Duplicate / near-duplicate photo groups of the current user.
 */
final class SimilarController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private SimilarPhotos $similar,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Groups with at least two photos the user can access; each file with its size so the UI
	 * can suggest which one to keep and how much space a cleanup frees.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function groups(bool $refresh = false): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$groups = $this->similar->groups($refresh);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$out = [];
		$reclaimable = 0;
		foreach ($groups as $group) {
			$files = [];
			foreach ($group['files'] as $fileId) {
				$node = $userFolder->getFirstNodeById($fileId);
				if ($node === null) {
					continue;
				}
				$files[] = ['fileid' => $fileId, 'name' => $node->getName(), 'path' => $userFolder->getRelativePath($node->getPath()), 'size' => $node->getSize(), 'mtime' => $node->getMTime()];
			}
			if (count($files) < 2) {
				continue;
			}
			usort($files, static fn ($a, $b) => $b['size'] <=> $a['size']);
			$groupBytes = array_sum(array_column($files, 'size')) - $files[0]['size'];
			$reclaimable += $groupBytes;
			$out[] = ['id' => $group['id'], 'files' => $files, 'size' => count($files), 'reclaimable' => $groupBytes];
		}
		return new JSONResponse(['groups' => $out, 'reclaimable' => $reclaimable]);
	}
}
