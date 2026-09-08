<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Prominence of a face in its photo (Memories "best photos first" sort):
 * the detector score and head pose, sharpness and brightness of the face crop,
 * and the composite quality computed from them (see Service\FaceQuality).
 */
final class Version012002000Date20260908120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('recognize_face_detections');
		foreach (['score', 'yaw', 'pitch', 'sharpness', 'brightness', 'quality'] as $column) {
			if (!$table->hasColumn($column)) {
				$table->addColumn($column, 'float', ['notnull' => false]);
			}
		}
		if (!$table->hasIndex('recognize_facedet_quality')) {
			$table->addIndex(['cluster_id', 'quality'], 'recognize_facedet_quality');
		}
		return $schema;
	}
}
