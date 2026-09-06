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
 * Natural-language photo search: queue for the CLIP classifier and the embeddings table
 */
final class Version012001000Date20260906200000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('recognize_queue_clip')) {
			$table = $schema->createTable('recognize_queue_clip');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', 'bigint', ['notnull' => true, 'length' => 64]);
			$table->addColumn('storage_id', 'bigint', ['notnull' => false, 'length' => 64]);
			$table->addColumn('root_id', 'bigint', ['notnull' => false, 'length' => 64]);
			$table->addColumn('update', 'boolean', ['notnull' => false]);
			$table->setPrimaryKey(['id'], 'recognize_clip_id');
			$table->addIndex(['file_id'], 'recognize_clip_file');
			$table->addIndex(['storage_id', 'root_id'], 'recognize_clip_storage');
		}

		if (!$schema->hasTable('recognize_clip_embeddings')) {
			$table = $schema->createTable('recognize_clip_embeddings');
			$table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', 'bigint', ['notnull' => true, 'length' => 64]);
			$table->addColumn('model', 'string', ['notnull' => true, 'length' => 128]);
			// float32 little-endian, dimensionality of the model (512 for ViT-B/32)
			$table->addColumn('vector', 'blob', ['notnull' => true]);
			// 64-bit difference hash as 16 hex chars, for near-duplicate detection
			$table->addColumn('phash', 'string', ['notnull' => false, 'length' => 16]);
			$table->addColumn('updated_at', 'bigint', ['notnull' => true, 'length' => 64, 'default' => 0]);
			$table->setPrimaryKey(['id'], 'recognize_clipemb_id');
			$table->addUniqueIndex(['file_id'], 'recognize_clipemb_file');
			$table->addIndex(['phash'], 'recognize_clipemb_phash');
		}

		return $schema;
	}
}
