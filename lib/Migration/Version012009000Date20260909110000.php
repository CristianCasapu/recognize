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
 * Is the face one of the people the picture is about, or is it part of the surroundings?
 * "subject" is how much the face stands out in its own photo — sharp where the lens was
 * focused and big enough to be in front — from 0 (a face far in the background) to 1
 * (the person the picture was taken of). See Service\FaceQuality::subjects().
 */
final class Version012009000Date20260909110000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('recognize_face_detections');
		if (!$table->hasColumn('subject')) {
			$table->addColumn('subject', 'float', ['notnull' => false]);
		}
		if (!$table->hasIndex('recognize_facedet_subject')) {
			$table->addIndex(['file_id', 'subject'], 'recognize_facedet_subject');
		}
		return $schema;
	}
}
