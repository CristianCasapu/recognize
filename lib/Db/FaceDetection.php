<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class FaceDetection
 *
 * @package OCA\Recognize\Db
 * @method int getFileId()
 * @method setFileId(int $fileId)
 * @method setUserId(string $userId)
 * @method string getUserId()
 * @method float getX()
 * @method float getY()
 * @method float getHeight()
 * @method float getWidth()
 * @method setX(float $x)
 * @method setY(float $y)
 * @method setHeight(float $height)
 * @method setWidth(float $width)
 * @method setClusterId(int|null $clusterId)
 * @method int|null getClusterId()
 * @method float getThreshold()
 * @method setThreshold(float $threshold)
 * @method float|null getScore()
 * @method setScore(float|null $score)
 * @method float|null getYaw()
 * @method setYaw(float|null $yaw)
 * @method float|null getPitch()
 * @method setPitch(float|null $pitch)
 * @method float|null getSharpness()
 * @method setSharpness(float|null $sharpness)
 * @method float|null getBrightness()
 * @method setBrightness(float|null $brightness)
 * @method float|null getQuality()
 * @method setQuality(float|null $quality)
 * @method float|null getSubject()
 * @method setSubject(float|null $subject)
 */
class FaceDetection extends Entity {
	protected $fileId;
	protected $userId;
	protected $x;
	protected $y;
	protected $height;
	protected $width;
	protected $faceVector;
	protected $clusterId;
	protected $threshold;
	protected $score;
	protected $yaw;
	protected $pitch;
	protected $sharpness;
	protected $brightness;
	protected $quality;
	protected $subject;
	/**
	 * @var string[]
	 */
	public static $columns = ['id', 'user_id', 'file_id', 'x', 'y', 'height', 'width', 'face_vector', 'cluster_id', 'threshold', 'score', 'yaw', 'pitch', 'sharpness', 'brightness', 'quality', 'subject'];
	/**
	 * @var string[]
	 */
	public static $fields = ['id', 'userId', 'fileId', 'x', 'y', 'height', 'width', 'faceVector', 'clusterId', 'threshold', 'score', 'yaw', 'pitch', 'sharpness', 'brightness', 'quality', 'subject'];

	public function __construct() {
		// add types in constructor
		$this->addType('fileId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('x', 'float');
		$this->addType('y', 'float');
		$this->addType('height', 'float');
		$this->addType('width', 'float');
		$this->addType('faceVector', 'json');
		$this->addType('clusterId', 'integer');
		$this->addType('threshold', 'float');
		foreach (['score', 'yaw', 'pitch', 'sharpness', 'brightness', 'quality', 'subject'] as $field) {
			$this->addType($field, 'float');
		}
	}

	public function toArray(): array {
		$array = [];
		foreach (static::$fields as $field) {
			if ($field === 'faceVector') {
				continue;
			}
			$array[$field] = $this->{$field};
		}
		return $array;
	}

	public function getVector(): array {
		return $this->getter('faceVector');
	}
	public function setVector(array $vector): void {
		$this->setter('faceVector', [$vector]);
	}
}
