const path = require('path')
const fs = require('fs/promises')

let tf, faceapi, Jimp
let PUREJS = false
if (process.env.RECOGNIZE_PUREJS === 'true') {
	tf = require('@tensorflow/tfjs')
	require('@tensorflow/tfjs-backend-wasm')
	faceapi = require('@vladmandic/face-api/dist/face-api.node-wasm.js')
	Jimp = require('jimp')
	PUREJS = true
} else {
	try {
		if (process.env.RECOGNIZE_GPU === 'true') {
			tf = require('@tensorflow/tfjs-node-gpu')
			faceapi = require('@vladmandic/face-api/dist/face-api.node-gpu.js')
		} else {
			tf = require('@tensorflow/tfjs-node')
			faceapi = require('@vladmandic/face-api/dist/face-api.node.js')
		}
	} catch (e) {
		console.error(e)
		console.error('Trying js-only mode')
		tf = require('@tensorflow/tfjs')
		require('@tensorflow/tfjs-backend-wasm')
		faceapi = require('@vladmandic/face-api/dist/face-api.node-wasm.js')
		Jimp = require('jimp')
		PUREJS = true
	}
}

/**
 * The SSD MobileNet detector scales every input to 512x512, so a face that covers
 * less than ~4% of a photo is only a dozen pixels for the detector and gets missed.
 * With tiling enabled (RECOGNIZE_FACES_TILING=true) the detector additionally runs on
 * overlapping tiles of the image; each tile is scaled to 512px on its own, so faces
 * appear TILE_GRID times larger. Landmarks and descriptors are always computed from
 * the pixels of the tile/image that was handed in, i.e. at preview resolution.
 */
const TILING = process.env.RECOGNIZE_FACES_TILING === 'true'
const TILE_GRID = 2
const TILE_OVERLAP = 0.2
const TILE_MIN_IMAGE_SIZE = 1024
const DUPLICATE_IOU = 0.4

if (process.argv.length < 3) throw new Error('Incorrect arguments: node classifier_faces.js ...<IMAGE_FILES> | node classify.js -')

/**
 *
 */
async function main() {
	const getStdin = (await import('get-stdin')).default
	let paths
	if (process.argv[2] === '-') {
		paths = (await getStdin()).split('\n')
	} else {
		paths = process.argv.slice(2)
	}

	await faceapi.nets.ssdMobilenetv1.loadFromDisk(path.resolve(__dirname, '..', 'node_modules/@vladmandic/face-api/model'))
	await faceapi.nets.faceLandmark68Net.loadFromDisk(path.resolve(__dirname, '..', 'node_modules/@vladmandic/face-api/model'))
	await faceapi.nets.faceRecognitionNet.loadFromDisk(path.resolve(__dirname, '..', 'node_modules/@vladmandic/face-api/model'))

	for (const path of paths) {
		try {
			let tensor
			if (PUREJS) {
				tensor = await createTensor(await Jimp.read(path), 3)
			} else {
				tensor = await tf.node.decodeImage(await fs.readFile(path), 3)
			}
			const [height, width] = tensor.shape
			const faces = await detectFaces(tensor, width, height)
			tensor.dispose()
			const vectors = faces
				.map(face => ({
					angle: face.angle,
					vector: face.vector,
					x: face.box.x / width,
					y: face.box.y / height,
					height: face.box.height / height,
					width: face.box.width / width,
					score: face.score,
				}))

			console.log(JSON.stringify(vectors))
		} catch (e) {
			console.error(e)
			console.log('[]')
		}
	}
}

/**
 * Detect faces in the whole image and, if enabled, additionally in overlapping tiles.
 * Returns faces with boxes in absolute pixels of the full image.
 *
 * @param {tf.Tensor3D} tensor
 * @param {number} width
 * @param {number} height
 */
async function detectFaces(tensor, width, height) {
	const faces = await detect(tensor, 0, 0)
	if (!TILING || Math.max(width, height) < TILE_MIN_IMAGE_SIZE) {
		return faces
	}

	const tileWidth = Math.ceil(width / TILE_GRID)
	const tileHeight = Math.ceil(height / TILE_GRID)
	const overlapX = Math.round(tileWidth * TILE_OVERLAP)
	const overlapY = Math.round(tileHeight * TILE_OVERLAP)
	for (let row = 0; row < TILE_GRID; row++) {
		for (let col = 0; col < TILE_GRID; col++) {
			const x0 = Math.max(0, col * tileWidth - overlapX)
			const y0 = Math.max(0, row * tileHeight - overlapY)
			const x1 = Math.min(width, (col + 1) * tileWidth + overlapX)
			const y1 = Math.min(height, (row + 1) * tileHeight + overlapY)
			const tile = tf.slice(tensor, [y0, x0, 0], [y1 - y0, x1 - x0, 3])
			let tileFaces
			try {
				tileFaces = await detect(tile, x0, y0)
			} finally {
				tile.dispose()
			}
			for (const face of tileFaces) {
				// Faces the full-image pass already found are kept from that pass (better context)
				if (!faces.some(existing => iou(existing.box, face.box) > DUPLICATE_IOU)) {
					faces.push(face)
				}
			}
		}
	}
	return faces
}

/**
 * Run the detector + landmarks + descriptor chain on a tensor and translate boxes by an offset.
 *
 * @param {tf.Tensor3D} tensor
 * @param {number} offsetX
 * @param {number} offsetY
 */
async function detect(tensor, offsetX, offsetY) {
	const results = await faceapi.detectAllFaces(tensor).withFaceLandmarks().withFaceDescriptors()
	return results.map(result => {
		const box = result.detection.box
		return {
			angle: result.angle,
			vector: Array.from(result.descriptor),
			score: result.detection.score,
			box: {
				x: box.x + offsetX,
				y: box.y + offsetY,
				width: box.width,
				height: box.height,
			},
		}
	})
}

/**
 * Intersection over union of two boxes ({x, y, width, height} in pixels)
 *
 * @param a
 * @param b
 */
function iou(a, b) {
	const x0 = Math.max(a.x, b.x)
	const y0 = Math.max(a.y, b.y)
	const x1 = Math.min(a.x + a.width, b.x + b.width)
	const y1 = Math.min(a.y + a.height, b.y + b.height)
	const intersection = Math.max(0, x1 - x0) * Math.max(0, y1 - y0)
	const union = a.width * a.height + b.width * b.height - intersection
	return union > 0 ? intersection / union : 0
}

tf.setBackend(PUREJS ? 'wasm' : 'tensorflow')
	.then(() => main())
	.catch(e => {
		console.error(e)
	})

/**
 * @param image
 */
async function createTensor(image) {
	const NUM_OF_CHANNELS = 3
	const values = new Float32Array(image.bitmap.width * image.bitmap.height * NUM_OF_CHANNELS)
	let i = 0
	image.scan(0, 0, image.bitmap.width, image.bitmap.height, (x, y) => {
		const pixel = Jimp.intToRGBA(image.getPixelColor(x, y))
		values[i * NUM_OF_CHANNELS + 0] = pixel.r
		values[i * NUM_OF_CHANNELS + 1] = pixel.g
		values[i * NUM_OF_CHANNELS + 2] = pixel.b
		i++
	})
	const outShape = [
		image.bitmap.height,
		image.bitmap.width,
		NUM_OF_CHANNELS,
	]
	const imageTensor = tf.tensor3d(values, outShape, 'float32')
	return imageTensor
}
