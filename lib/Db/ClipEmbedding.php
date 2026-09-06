<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getModel()
 * @method void setModel(string $model)
 * @method string getVector()
 * @method void setVector(string $vector)
 * @method ?string getPhash()
 * @method void setPhash(?string $phash)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
final class ClipEmbedding extends Entity {
	public $id;
	protected $fileId;
	protected $model;
	protected $vector;
	protected $phash;
	protected $updatedAt;

	public static array $columns = ['id', 'file_id', 'model', 'vector', 'phash', 'updated_at'];

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('model', 'string');
		$this->addType('vector', 'string');
		$this->addType('phash', 'string');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * @return list<float>
	 */
	public function getFloats(): array {
		return array_values(unpack('g*', $this->getVector()) ?: []);
	}

	/**
	 * @param list<float> $floats
	 */
	public static function packFloats(array $floats): string {
		return pack('g*', ...$floats);
	}
}
