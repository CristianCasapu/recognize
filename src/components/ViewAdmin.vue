<!--
  - Copyright (c) 2021. The Recognize contributors.
  -
  - This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
  -->

<template>
	<div id="recognize">
		<figure v-if="loading" class="icon-loading loading" />
		<figure v-if="!loading && success" class="icon-checkmark success" />
		<NcSettingsSection :name="t('recognize', 'Status')">
			<NcNoteCard v-if="modelsDownloaded" show-alert type="success">
				{{ t('recognize', 'The machine learning models have been downloaded successfully.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="!modelsDownloaded" type="warning">
				{{ t('recognize', 'The machine learning models still need to be downloaded.') }}
			</NcNoteCard>
			<NcNoteCard v-if="tagsEnabled" show-alert type="success">
				{{ t('recognize', 'The systemtags app is enabled.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="!tagsEnabled" type="warning">
				{{ t('recognize', 'The systemtags app is currently disabled. Some features of this app will not work.') }}
			</NcNoteCard>
			<NcNoteCard v-if="nodejs === false" type="warning">
				{{ t('recognize', 'Could not execute the Node.js binary. You may need to set the path to a working binary manually.') }}
			</NcNoteCard>
			<NcNoteCard v-if="cron !== undefined && cron !== 'cron'" type="error">
				{{ t('recognize', 'Background Jobs are not executed via cron. Recognize requires background jobs to be executed via cron.') }}
			</NcNoteCard>
			<NcNoteCard v-if="errors.length" type="error">
				{{ t('recognize', 'Recent errors and warnings (newest first). Setup problems are also listed under Administration settings › Overview, and admins receive a notification when a background job fails.') }}
				<ul class="recognize-errors">
					<li v-for="(entry, index) in errors" :key="index">
						<code>{{ showDate(entry.time) }}</code> <strong>{{ entry.level }}</strong>: {{ entry.message }}<span v-if="entry.detail"> — {{ entry.detail }}</span><span v-if="entry.count > 1"> (×{{ entry.count }})</span>
					</li>
				</ul>
				<button class="button" @click="clearErrors">
					{{ t('recognize', 'Clear error list') }}
				</button>
			</NcNoteCard>
			<NcNoteCard v-else show-alert type="success">
				{{ t('recognize', 'No recent errors or warnings.') }}
			</NcNoteCard>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['notifications.enabled']" type="switch" @update:checked="onChange">
					{{ t('recognize', 'Notify administrators (Nextcloud notification) when a classification or clustering job fails') }}
				</NcCheckboxRadioSwitch>
			</p>
			<p>{{ t('recognize', 'If Node.js, libtensorflow or FFmpeg are missing (see below), you can re-run the dependency installation here. Libraries that need root access (CUDA, cuDNN) have to be installed on the server, see the GPU section.') }}</p>
			<button class="button" :disabled="installBusy" @click="onInstallDeps">
				<span v-if="installBusy" class="icon-loading-small" />
				{{ t('recognize', 'Re-install dependencies (Node.js, libtensorflow, FFmpeg)') }}
			</button>
			<ul v-if="installMessages.length" class="recognize-errors">
				<li v-for="(message, index) in installMessages" :key="index">
					{{ message }}
				</li>
			</ul>
			<template v-if="nodejs && (libtensorflow || wasmtensorflow) && cron === 'cron'">
				<template v-if="settings['faces.enabled'] || settings['imagenet.enabled'] || settings['musicnn.enabled'] || settings['movinet.enabled']">
					<NcNoteCard show-alert type="success">
						{{ t('recognize', 'The app is installed and will automatically classify files in background processes.') }}
					</NcNoteCard>
				</template>
				<p v-else>
					{{ t('recognize', 'None of the tagging options below are currently selected. The app will currently do nothing.') }}
				</p>
			</template>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Face recognition')">
			<template v-if="settings['faces.enabled']">
				<NcNoteCard v-if="faceProgress && faceProgress.running" show-alert type="info">
					<strong>{{ t('recognize', 'Scanning photos for faces: {scanned} of {total} ({percent} %)', { scanned: faceProgress.scanned, total: faceProgress.total, percent: faceProgress.percent ?? 0 }) }}</strong>
					— {{ t('recognize', '{rate} photos/min', { rate: faceProgress.ratePerMinute }) }}<span v-if="faceProgress.etaSeconds !== null">, {{ t('recognize', 'about {min} min left', { min: Math.ceil(faceProgress.etaSeconds / 60) }) }}</span>.
					{{ t('recognize', '{faces} faces in {photos} photos so far, {waiting} waiting for clustering, {clusters} people ({named} named).', { faces: faceProgress.faces, photos: faceProgress.photosWithFaces, waiting: faceProgress.waitingForClustering, clusters: faceProgress.clusters, named: faceProgress.named }) }}
					<progress :value="faceProgress.percent ?? 0" max="100" style="width: 100%" />
				</NcNoteCard>
				<NcNoteCard v-else-if="faceProgress" show-alert type="success">
					{{ t('recognize', 'No face scan running. {faces} faces in {photos} photos, {clusters} people ({named} named); {waiting} faces waiting for clustering, {queued} photos queued.', { faces: faceProgress.faces, photos: faceProgress.photosWithFaces, clusters: faceProgress.clusters, named: faceProgress.named, waiting: faceProgress.waitingForClustering, queued: faceProgress.queued }) }}
					<span v-if="faceProgress.lastActivity">{{ t('recognize', 'Last photo scanned: {when}.', { when: new Date(faceProgress.lastActivity * 1000).toLocaleString() }) }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="settings['faces.status'] === true" show-alert type="success">
					{{ t('recognize', 'Face recognition is working. ') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['faces.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred during face recognition, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for status reports on face recognition. If this message persists beyond 15 minutes, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Face recognition:') }} {{ countQueued.faces }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['faces.lastFile']) }}<span v-if="facesJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ facesJobs.scheduled }}, {{ facesJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(facesJobs.lastRun) : '' }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="countQueued && countQueued.faces && facesJobs && !facesJobs.scheduled" show-alert type="error">
					{{ t('recognize', 'There are queued files in the face recognition queue but no background job is scheduled to process them.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Face clustering:') }} {{ countQueued.clusterFaces }} {{ t('recognize', 'faces left to cluster') }}, {{ t('recognize', 'Last clustering run: ') }} {{ showDate(settings['clusterFaces.lastRun']) }}<span v-if="clusterFacesJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ clusterFacesJobs.scheduled }}, {{ clusterFacesJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(clusterFacesJobs.lastRun) : '' }}</span><br>
					<small>{{ t('recognize', 'A minimum of 120 faces per user is necessary for clustering to kick in') }}</small>
				</NcNoteCard>
			</template>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['faces.enabled']" type="switch" @update:checked="onChange">
					{{ t('recognize', 'Enable face recognition (groups photos by faces that appear in them; UI is in the photos app)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['faces.enabled']"
					:value.sync="settings['faces.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~500 or more, in WASM mode ~50 is recommended)')"
					:title="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~500 or more, in WASM mode ~50 is recommended)')"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<h3>{{ t('recognize', 'Face model') }}</h3>
			<p>{{ t('recognize', 'Two face models are available. The built-in face-api model (Node.js, 128 numbers per face) is fast but often mixes up similar looking people, especially children and relatives. InsightFace (RetinaFace detector + ArcFace, 512 numbers per face, runs in Python through ONNX Runtime, on the GPU if available) separates people far more reliably and lets new photos be assigned to known people directly. The two are not compatible: switching removes all detected faces and clusters; the person names are saved and restored automatically after the next scan and clustering.') }}</p>
			<template v-if="faceBackend">
				<NcNoteCard :type="faceBackend.backend === 'insightface' ? 'success' : 'info'">
					{{ t('recognize', 'Active face model: {backend}', { backend: faceBackend.backend === 'insightface' ? 'InsightFace (RetinaFace + ArcFace)' : 'face-api (built-in)' }) }}
					<span v-if="faceBackend.pendingNameSnapshot">— {{ t('recognize', 'person names from the previous model will be restored after the next clustering run') }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="faceBackend.insightface.ok" show-alert type="success">
					{{ t('recognize', 'InsightFace {version} with ONNX Runtime {ort} is installed ({device}) in {python}.', { version: faceBackend.insightface.versions.insightface, ort: faceBackend.insightface.versions.onnxruntime, device: faceBackend.insightface.gpu ? 'GPU' : 'CPU', python: faceBackend.insightface.python }) }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'InsightFace is not installed ({detail}). It can be installed without root access into the Nextcloud data directory: click the button below (runs as a background job, needs python3, python3-venv and a C compiler on the server, downloads about 500 MB) or run on the server:', { detail: faceBackend.insightface.output ? faceBackend.insightface.output.split('\n').pop() : '' }) }}
					<pre><code>occ recognize:install-insightface        # add --cpu without an NVIDIA GPU
occ recognize:switch-face-backend insightface</code></pre>
				</NcNoteCard>
				<div v-if="faceBackend.install" class="recognize-output-box">
					<strong>{{ faceBackend.install.running ? t('recognize', 'Installation in progress…') : (faceBackend.install.ok ? t('recognize', 'Installation finished') : t('recognize', 'Installation failed')) }}</strong>
					<pre class="recognize-output">{{ (faceBackend.install.lines || []).slice(-15).join('\n') }}</pre>
				</div>
				<p>
					<button v-if="!faceBackend.insightface.ok"
						class="button"
						:disabled="backendBusy || (faceBackend.install && faceBackend.install.running) || faceBackend.installScheduled"
						@click="onInstallInsightface">
						{{ t('recognize', 'Install InsightFace (background job)') }}
					</button>
					<button v-if="faceBackend.backend !== 'insightface'"
						class="button primary"
						:disabled="backendBusy || !faceBackend.insightface.ok || !settings['faces.enabled']"
						@click="onSwitchFaceBackend('insightface')">
						{{ t('recognize', 'Switch to InsightFace (recommended)') }}
					</button>
					<button v-else
						class="button"
						:disabled="backendBusy || !settings['faces.enabled']"
						@click="onSwitchFaceBackend('faceapi')">
						{{ t('recognize', 'Switch back to face-api') }}
					</button>
					<button class="button" :disabled="backendBusy" @click="getFaceBackendStatus(true)">
						{{ t('recognize', 'Re-check') }}
					</button>
					<span v-if="backendBusy" class="icon-loading-small" />
				</p>
				<p>
					<NcTextField :value.sync="settings['python_binary']"
						:label-visible="true"
						:label="t('recognize', 'Python binary of the InsightFace environment (optional; default: <data>/appdata_*/recognize/insightface/venv/bin/python)')"
						@update:value="onChange" />
				</p>
			</template>
			<p>&nbsp;</p>
			<h3>{{ t('recognize', 'Small faces') }}</h3>
			<p>{{ t('recognize', 'The face detector looks at a 512px copy of each image, so faces that are small in the picture (group photos, people in the background) are not found. With tiling enabled the detector additionally scans overlapping quarters of the image (4 extra passes per image, fast on a GPU). A larger preview improves the quality of the face descriptors of small faces. Files that were already processed are not scanned again automatically; use "Reset faces for classified files" and "Rescan all files" below to apply the new settings to existing photos.') }}</p>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['faces.tiling']"
					:disabled="!settings['faces.enabled']"
					type="switch"
					@update:checked="onChange">
					{{ t('recognize', 'Also scan image tiles to find small faces') }}
				</NcCheckboxRadioSwitch>
			</p>
			<p>
				<label for="recognize-preview-dimension">{{ t('recognize', 'Size of the image copy handed to the face detector (pixels, longest side)') }}</label><br>
				<select id="recognize-preview-dimension"
					v-model="settings['faces.previewDimension']"
					:disabled="!settings['faces.enabled']"
					@change="onChange">
					<option value="1024">
						1024 ({{ t('recognize', 'default') }})
					</option>
					<option value="2048">
						2048
					</option>
					<option value="4096">
						4096
					</option>
				</select>
			</p>
			<p>
				<NcTextField :disabled="!settings['faces.enabled']"
					:value.sync="settings['faces.minDetectionSize']"
					type="number"
					:min="0.005"
					:max="0.2"
					:step="0.005"
					:label-visible="true"
					:label="t('recognize', 'Ignore faces smaller than this fraction of the image when clustering (default 0.03; lower it to e.g. 0.015 together with tiling and a 2048px preview)')"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<h3>{{ t('recognize', 'Who the picture is about') }}</h3>
			<p>{{ t('recognize', 'A photograph is normally taken of the people in front of the camera: they are large in the frame and they are where the lens focused. Whoever stands further back — smaller and softer, outside the depth of field — belongs to the surroundings. Every face is therefore weighed against the other faces of the same photo and gets a "subject" score between 0 and 1; faces at or above the threshold count as the people the picture is about. Nothing is skipped when scanning: every face is still detected and clustered. Run "occ recognize:face-subjects" once after changing anything measured, or "occ recognize:face-subjects --all" to weigh every photo again.') }}</p>
			<p>
				<NcTextField :disabled="!settings['faces.enabled']"
					:value.sync="settings['faces.subjectThreshold']"
					type="number"
					:min="0"
					:max="1"
					:step="0.05"
					:label-visible="true"
					:label="t('recognize', 'From this score a face is one of the people the picture is about (default 0.55)')"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<h3>{{ t('recognize', 'Automatic merging of clusters') }}</h3>
			<p>{{ t('recognize', 'Clustering runs in batches and never merges two existing clusters, so the same person often ends up as one named and several unnamed clusters. When a threshold above 0 is set, unnamed clusters whose average face is closer than the threshold to a named cluster (and clearly farther from every other named cluster) are merged into it after every clustering run. Clusters that appear together in the same photo are never merged (a person cannot be in a photo twice). Typical thresholds: 0.4 for face-api, 0.85 for InsightFace. Use "Preview" to see what would be merged with the current threshold before enabling it.') }}</p>
			<p>
				<NcTextField :disabled="!settings['faces.enabled']"
					:value.sync="settings['faces.autoMergeThreshold']"
					type="number"
					:min="0"
					:max="1"
					:step="0.01"
					:label-visible="true"
					:label="t('recognize', 'Maximum centroid distance for automatic merges (0 disables automatic merging)')"
					@update:value="onChange" />
			</p>
			<p>
				<button class="button" :disabled="mergeBusy || !settings['faces.enabled']" @click="getMergeSuggestions">
					{{ t('recognize', 'Preview merge candidates') }}
				</button>
				<button class="button" :disabled="mergeBusy || !settings['faces.enabled']" @click="onAutoMerge">
					{{ t('recognize', 'Merge now') }}
				</button>
				<span v-if="mergeBusy" class="icon-loading-small" />
			</p>
			<NcNoteCard v-if="mergeResult" type="success">
				{{ t('recognize', 'Merged {count} cluster(s) with threshold {threshold}.', { count: mergeCandidates(mergeResult).length, threshold: mergeResult.threshold }) }}
				<ul class="recognize-errors">
					<li v-for="merged in mergeCandidates(mergeResult)" :key="merged.user + '-' + merged.clusterId">
						{{ t('recognize', 'cluster #{cluster} ({size} faces) → {target} (distance {distance})', { cluster: merged.clusterId, size: merged.size, target: merged.targetTitle, distance: merged.distance }) }}
					</li>
				</ul>
			</NcNoteCard>
			<template v-if="mergeSuggestions">
				<p v-if="!mergeCandidates(mergeSuggestions).length">
					{{ t('recognize', 'No unnamed clusters with a named counterpart were found.') }}
				</p>
				<table v-else class="recognize-table">
					<thead>
						<tr>
							<th>{{ t('recognize', 'User') }}</th>
							<th>{{ t('recognize', 'Unnamed cluster') }}</th>
							<th>{{ t('recognize', 'Faces') }}</th>
							<th>{{ t('recognize', 'Nearest person') }}</th>
							<th>{{ t('recognize', 'Distance') }}</th>
							<th>{{ t('recognize', '2nd nearest') }}</th>
							<th>{{ t('recognize', 'Photos together') }}</th>
							<th>{{ t('recognize', 'Would merge (threshold {threshold})', { threshold: mergeSuggestions.threshold }) }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="candidate in mergeCandidates(mergeSuggestions)" :key="candidate.user + '-' + candidate.clusterId">
							<td>{{ candidate.user }}</td>
							<td>#{{ candidate.clusterId }}</td>
							<td>{{ candidate.size }}</td>
							<td>{{ candidate.targetTitle }}</td>
							<td>{{ candidate.distance }}</td>
							<td>{{ candidate.secondTitle ? candidate.secondTitle + ' (' + candidate.secondDistance + ')' : '-' }}</td>
							<td>{{ candidate.sharedFiles }}</td>
							<td>{{ candidate.mergeable ? t('recognize', 'yes') : t('recognize', 'no') }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Natural-language photo search')">
			<p>{{ t('recognize', 'Lets people search their photos in plain language ("sunset at the sea", "birthday cake", "dog in the snow") in Memories. Every photo gets a CLIP embedding (a multilingual model that maps images and text into the same space, ~1.6 GB, runs through the same Python environment as InsightFace, on the GPU when available); the same pass computes a perceptual hash used to find near-duplicate photos.') }}</p>
			<template v-if="settings['clip.enabled']">
				<NcNoteCard v-if="settings['clip.status'] === true" show-alert type="success">
					{{ t('recognize', 'Photo indexing for search is working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['clip.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred while indexing photos for search, see "Recent errors" above.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for the first indexing run.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Search index:') }} {{ countQueued.clip }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['clip.lastFile']) }}<span v-if="clipJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ clipJobs.scheduled }}</span><span v-if="clip">, {{ t('recognize', '{count} photos indexed', { count: clip.indexed }) }}</span>
				</NcNoteCard>
			</template>
			<template v-if="clip">
				<NcNoteCard v-if="clip.installed" show-alert type="success">
					{{ t('recognize', 'Model {model} is installed in {dir}.', { model: clip.model, dir: clip.dir }) }}
				</NcNoteCard>
				<NcNoteCard v-else-if="!clip.pythonReady" type="warning">
					{{ t('recognize', 'The Python environment is missing: install InsightFace first (section "Face model"), the search model uses the same environment.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'The search model {model} is not downloaded yet. Click "Download model" (background job, ~1.6 GB) or run:', { model: clip.model }) }}
					<pre><code>occ recognize:install-clip</code></pre>
				</NcNoteCard>
				<div v-if="clip.install" class="recognize-output-box">
					<strong>{{ clip.install.running ? t('recognize', 'Download in progress…') : (clip.install.ok ? t('recognize', 'Download finished') : t('recognize', 'Download failed')) }}</strong>
					<pre class="recognize-output">{{ (clip.install.lines || []).slice(-10).join('\n') }}</pre>
				</div>
				<p>
					<button v-if="!clip.installed"
						class="button primary"
						:disabled="clipBusy || !clip.pythonReady || (clip.install && clip.install.running) || clip.installScheduled"
						@click="onInstallClip">
						{{ t('recognize', 'Download model and index photos') }}
					</button>
				</p>
			</template>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['clip.enabled']" type="switch" :disabled="clip && !clip.installed" @update:checked="onChange">
					{{ t('recognize', 'Enable natural-language photo search (Memories search bar)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['clip.enabled']"
					:value.sync="settings['clip.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes)')"
					@update:value="onChange" />
			</p>
			<p>
				<NcTextField :value.sync="settings['clip.minScore']"
					type="number"
					:min="0"
					:max="1"
					:step="0.01"
					:label-visible="true"
					:label="t('recognize', 'Minimum similarity for a photo to count as a match (empty = 0.17; lower shows more, less precise results)')"
					@update:value="onChange" />
			</p>
			<template v-if="clip && clip.installed && settings['clip.enabled']">
				<p>
					<NcTextField :value.sync="clipQuery"
						:label-visible="true"
						:label="t('recognize', 'Try a search (e.g. \'sunset\', \'tort\', \'copil pe bicicletă\')')"
						@keyup.enter="onClipTest" />
					<button class="button" :disabled="clipBusy || !clipQuery" @click="onClipTest">
						{{ t('recognize', 'Search') }}
					</button>
				</p>
				<ul v-if="clipResults" class="recognize-errors">
					<li v-if="!clipResults.length">
						{{ t('recognize', 'No matches above the minimum similarity.') }}
					</li>
					<li v-for="r in clipResults" :key="r.fileid">
						{{ r.score }} — {{ r.path }}
					</li>
				</ul>
			</template>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Object detection & landmark recognition')">
			<template v-if="settings['imagenet.enabled']">
				<NcNoteCard v-if="settings['imagenet.status'] === true" show-alert type="success">
					{{ t('recognize', 'Object recognition is working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['imagenet.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred during object recognition, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for status reports on object recognition. If this message persists beyond 15 minutes, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Object recognition:') }} {{ countQueued.imagenet }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['imagenet.lastFile']) }}<span v-if="imagenetJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ imagenetJobs.scheduled }}, {{ imagenetJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(imagenetJobs.lastRun) : '' }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="countQueued && countQueued.imagenet && imagenetJobs && !imagenetJobs.scheduled" show-alert type="error">
					{{ t('recognize', 'There are queued files in the object detection queue but no background job is scheduled to process them.') }}
				</NcNoteCard>
			</template>
			<template v-if="settings['landmarks.enabled']">
				<NcNoteCard v-if="settings['landmarks.status'] === true" show-alert type="success">
					{{ t('recognize', 'Landmark recognition is working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['landmarks.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred during landmark recognition, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for status reports on landmark recognition. If this message persists beyond 15 minutes, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Landmark recognition:') }} {{ countQueued.landmarks }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['landmarks.lastFile']) }}<span v-if="landmarksJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ landmarksJobs.scheduled }}, {{ landmarksJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(landmarksJobs.lastRun) : '' }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="countQueued && countQueued.landmarks && landmarksJobs && !landmarksJobs.scheduled" show-alert type="error">
					{{ t('recognize', 'There are queued files in the landmarks queue but no background job is scheduled to process them.') }}
				</NcNoteCard>
			</template>

			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['imagenet.enabled']" type="switch" @update:checked="onChange">
					{{ t('recognize', 'Enable object recognition (e.g. food, vehicles, landscapes)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['imagenet.enabled']"
					:value.sync="settings['imagenet.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					:title="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['landmarks.enabled']"
					type="switch"
					:disabled="!settings['imagenet.enabled']"
					@update:checked="onChange">
					{{ t('recognize', 'Enable landmark recognition (e.g. Eiffel Tower, Golden Gate Bridge)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['imagenet.enabled'] || !settings['landmarks.enabled']"
					:value.sync="settings['landmarks.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					:title="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					@update:value="onChange" />
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Audio tagging')">
			<template v-if="settings['musicnn.enabled']">
				<NcNoteCard v-if="settings['musicnn.status'] === true" show-alert type="success">
					{{ t('recognize', 'Audio recognition is working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['musicnn.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred during audio recognition, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for status reports on audio recognition. If this message persists beyond 15 minutes, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Music genre recognition:') }} {{ countQueued.musicnn }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['musicnn.lastFile']) }}<span v-if="musicnnJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ musicnnJobs.scheduled }}, {{ musicnnJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(musicnnJobs.lastRun) : '' }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="countQueued && countQueued.musicnn && musicnnJobs && !musicnnJobs.scheduled" show-alert type="error">
					{{ t('recognize', 'There are queued files but no background job is scheduled to process them.') }}
				</NcNoteCard>
			</template>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['musicnn.enabled']" type="switch" @update:checked="onChange">
					{{ t('recognize', 'Enable music genre recognition (e.g. pop, rock, folk, metal, new age)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['musicnn.enabled']"
					:value.sync="settings['musicnn.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					:title="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~100 or more, in WASM mode ~20 is recommended)')"
					@update:value="onChange" />
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Video tagging')">
			<template v-if="settings['movinet.enabled']">
				<NcNoteCard v-if="settings['movinet.status'] === true" show-alert type="success">
					{{ t('recognize', 'Video recognition is working.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['movinet.status'] === false" show-alert type="error">
					{{ t('recognize', 'An error occurred during video recognition, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-else type="warning">
					{{ t('recognize', 'Waiting for status reports on video recognition. If this message persists beyond 15 minutes, please check the Nextcloud logs.') }}
				</NcNoteCard>
				<NcNoteCard v-if="countQueued" type="info">
					{{ t('recognize', 'Video recognition:') }} {{ countQueued.movinet }} {{ t('recognize', 'Queued files') }}, {{ t('recognize', 'Last classification: ') }} {{ showDate(settings['movinet.lastFile']) }}<span v-if="movinetJobs">, {{ t('recognize', 'Scheduled background jobs: ') }} {{ movinetJobs.scheduled }}, {{ movinetJobs.lastRun ? t('recognize', 'Last background job execution: ') + showDate(movinetJobs.lastRun) : '' }}</span>
				</NcNoteCard>
				<NcNoteCard v-if="countQueued && countQueued.movinet && movinetJobs && !movinetJobs.scheduled" show-alert type="error">
					{{ t('recognize', 'There are queued files but no background job is scheduled to process them.') }}
				</NcNoteCard>
			</template>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['movinet.enabled']"
					type="switch"
					:disabled="(platform !== 'x86_64' || settings['tensorflow.purejs']) && !settings['movinet.enabled']"
					@update:checked="onChange">
					{{ t('recognize', 'Enable human action recognition (e.g. arm wrestling, dribbling basketball, hula hooping)') }}
				</NcCheckboxRadioSwitch>
				<NcTextField :disabled="!settings['movinet.enabled']"
					:value.sync="settings['movinet.batchSize']"
					:label-visible="true"
					:label="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~20 or more, in WASM mode ~5 is recommended)')"
					:title="t('recognize', 'The number of files to process per job run (A job will be scheduled every 5 minutes; For normal operation ~20 or more, in WASM mode ~5 is recommended)')"
					@update:value="onChange" />
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Reset')">
			<p>{{ t('recognize', 'Click the button below to remove all tags from all files that have been classified so far.') }}</p>
			<button class="button" @click="onReset">
				{{ t('recognize', 'Reset tags for classified files') }}
			</button>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'Click the button below to remove all face detections from all files that have been classified so far. Only do this on purpose: all photos are scanned again afterwards. The names of people are saved and restored automatically; manual face tags are lost.') }}</p>
			<button class="button" @click="onResetFaces">
				{{ t('recognize', 'Reset faces for classified files') }}
			</button>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'Click the button below to rescan all files in this instance and add them to the classifier queues.') }}</p>
			<button class="button" @click="onRescan">
				{{ t('recognize', 'Rescan all files') }}
			</button>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'Click the button below to clear the classifier queues and clear all background jobs. This is useful when you want to do the initial classification using the terminal command.') }}</p>
			<button class="button" @click="onClearJobs">
				{{ t('recognize', 'Clear queues and background jobs') }}
			</button>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Resource usage') ">
			<p>{{ t('recognize', 'By default all available CPU cores will be used which may put your system under considerable load. To avoid this, you can limit the amount of CPU Cores used. (Note: In WASM mode, currently only 1 core can be used at all times.)') }}</p>
			<p>
				<NcTextField :value.sync="settings['tensorflow.cores']"
					type="number"
					:min="0"
					:step="1"
					:max="32"
					:label="t('recognize', 'Number of CPU Cores (0 for no limit)')"
					:label-visible="true"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'By default, recognize will only ever run one classifier process at a time. If you have a lot of resources available and want to run as many processes in parallel as possible, you can turn on concurrency here.') }}</p>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['concurrency.enabled']"
					type="switch"
					@update:checked="onChange">
					{{ t('recognize', 'Enable unlimited concurrency of classifier processes') }}
				</NcCheckboxRadioSwitch>
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Tensorflow WASM mode')">
			<p v-if="avx === undefined || platform === undefined || musl === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking CPU') }}
			</p>
			<NcNoteCard v-else-if="avx === null || platform === null || musl === null" type="warning">
				{{ t('recognize', 'Could not check whether your machine supports native TensorFlow operation. Make sure your OS has GNU lib C, your CPU supports AVX instructions and you are running on x86. If one of these things is not the case, you will need to run in WASM mode.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="avx && platform === 'x86_64' && !musl" type="success">
				{{ t('recognize', 'Your machine supports native TensorFlow operation, you do not need WASM mode.') }}
			</NcNoteCard>
			<template v-else>
				<p>
					{{ t('recognize', 'WASM mode was activated automatically, because your machine does not support native TensorFlow operation:') }}
				</p>
				<ul>
					<li v-for="reason in pureJSReasons" :key="reason">
						{{ reason }}
					</li>
				</ul>
			</template>
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['tensorflow.purejs']" type="switch" @update:checked="onChange">
					{{ t('recognize', 'Enable WASM mode') }}
				</NcCheckboxRadioSwitch>
			</p>
			<p>
				{{ t('recognize', 'Recognize uses Tensorflow for running the machine learning models. Not all installations support Tensorflow, either because the CPU does not support AVX instructions, or because the platform is not x86 (ie. on a Raspberry Pi, which is ARM), or because the Operating System that your nextcloud runs on (when using docker, then that is the OS within the docker image) does not come with GNU lib C (for example Alpine Linux, which is also used by Nextcloud AIO). In most cases, even if your installation does not support native Tensorflow operation, you can still run Tensorflow using WebAssembly (WASM) in Node.js. This is somewhat slower but still works.') }}
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Tensorflow GPU mode')">
			<p>
				<NcCheckboxRadioSwitch :checked.sync="settings['tensorflow.gpu']"
					type="switch"
					:disabled="settings['tensorflow.purejs']"
					@update:checked="onChange">
					{{ t('recognize', 'Enable GPU mode') }}
				</NcCheckboxRadioSwitch>
			</p>
			<p>
				{{ t('recognize', 'Like most machine learning models, Recognize will run even faster when using a GPU. Setting this up is non-trivial but works well when everything is setup correctly.') }}
			</p>
			<p>
				<a href="https://github.com/nextcloud/recognize/wiki/GPU-mode">{{ t('recognize', 'Learn how to setup GPU mode with Recognize') }}</a>
			</p>
			<p v-if="tensorflow === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking TensorFlow') }}
			</p>
			<template v-else-if="settings['tensorflow.gpu']">
				<NcNoteCard v-if="tensorflow.ok && tensorflow.mode === 'gpu'" show-alert type="success">
					{{ t('recognize', 'TensorFlow is using the GPU.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="tensorflow.deviceSandbox && tensorflow.cli && tensorflow.cli.ok && tensorflow.cli.mode === 'gpu'" show-alert type="success">
					{{ t('recognize', 'TensorFlow is using the GPU (verified from a terminal/cron process on {date}). The web server process itself cannot see the GPU device, which does not affect the background jobs.', { date: showDate(tensorflow.cli.checkedAt) }) }}
				</NcNoteCard>
				<NcNoteCard v-else-if="tensorflow.deviceSandbox" type="warning">
					{{ t('recognize', 'The web server process cannot see any NVIDIA GPU device (/dev/nvidia0). This is usually caused by the PHP-FPM systemd unit running with PrivateDevices=yes and does not affect background jobs started by cron. Verify from a terminal with "occ setupchecks" (the result will be shown here), or add a systemd drop-in for the PHP-FPM service with "PrivateDevices=no".') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="tensorflow.mode === 'gpu'" type="warning">
					{{ t('recognize', 'GPU mode is enabled but TensorFlow cannot use the GPU, so classification silently runs on the CPU.') }}
					<span v-if="tensorflow.missingLibraries && tensorflow.missingLibraries.length">{{ t('recognize', 'Node.js could not load these shared libraries: {libraries}.', { libraries: tensorflow.missingLibraries.join(', ') }) }}</span>
					{{ t('recognize', 'TensorFlow 2.9 (used by tfjs-node-gpu 4.x) needs the CUDA 11 runtime libraries (libcudart.so.11.0, libcublas.so.11, libcufft.so.10, libcurand.so.10, libcusolver.so.11, libcusparse.so.11) and cuDNN 8 (libcudnn.so.8). Install them as root on the server, e.g. on Debian/Ubuntu:') }}
					<pre><code>apt install nvidia-cuda-toolkit nvidia-cudnn   # or the cuda-11-x / libcudnn8 packages from the NVIDIA repository
ldconfig -p | grep -E 'cudnn|cudart'          # both must be listed</code></pre>
					{{ t('recognize', 'If the libraries are installed in a directory the dynamic linker does not know, enter that directory below (LD_LIBRARY_PATH for the classifier processes). Then click "Re-check".') }}
				</NcNoteCard>
			</template>
			<p>
				<NcTextField :value.sync="settings['tensorflow.ldLibraryPath']"
					:label-visible="true"
					:label="t('recognize', 'LD_LIBRARY_PATH for the classifier processes (optional, e.g. /usr/local/cuda-11.8/lib64:/opt/cudnn8/lib)')"
					@update:value="onChange" />
			</p>
			<p>
				<button class="button" @click="getTensorflowStatus">
					{{ t('recognize', 'Re-check TensorFlow / GPU') }}
				</button>
			</p>
			<details v-if="tensorflow && tensorflow.output">
				<summary>{{ t('recognize', 'Node.js output of the last check') }}</summary>
				<pre class="recognize-output">{{ tensorflow.output }}</pre>
			</details>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Node.js')">
			<p v-if="nodejs === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking Node.js') }}
			</p>
			<NcNoteCard v-else-if="nodejs === false" type="warning">
				{{ t('recognize', 'Could not execute the Node.js binary. You may need to set the path to a working binary manually.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="success">
				{{ t('recognize', 'Node.js {version} binary was installed successfully.', { version: nodejs }) }}
			</NcNoteCard>
			<p v-if="libtensorflow === undefined || wasmtensorflow === undefined || gputensorflow === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking libtensorflow') }}
			</p>
			<template v-if="settings['tensorflow.purejs'] === false">
				<NcNoteCard v-if="nodejs !== false && libtensorflow === false" type="warning">
					{{ t('recognize', 'Could not load libtensorflow in Node.js. You can try to manually install libtensorflow or run in WASM mode.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="nodejs !== false && libtensorflow === true && settings['tensorflow.gpu'] === true && gputensorflow === false" type="warning">
					{{ t('recognize', 'Successfully loaded libtensorflow in Node.js, but couldn\'t load GPU. Make sure CUDA Toolkit and cuDNN are installed and accessible, or turn off GPU mode.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="settings['tensorflow.gpu'] === false && libtensorflow === true" type="success">
					{{ t('recognize', 'Libtensorflow was loaded successfully into Node.js.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="libtensorflow === true" type="success">
					{{ t('recognize', 'Libtensorflow was loaded successfully into Node.js.') }}
				</NcNoteCard>
			</template>
			<template v-else>
				<NcNoteCard v-if="nodejs !== false && wasmtensorflow === false" type="warning">
					{{ t('recognize', 'Could not load Tensorflow WASM in Node.js. Something is wrong with your setup.') }}
				</NcNoteCard>
				<NcNoteCard v-else-if="wasmtensorflow === true" type="success">
					{{ t('recognize', 'Tensorflow WASM was loaded successfully into Node.js.') }}
				</NcNoteCard>
			</template>
			<p>
				{{ t('recognize', 'If the shipped Node.js binary doesn\'t work on your system for some reason you can set the path to a custom node.js binary. Currently supported is Node v20.9 and newer v20 releases.') }}
			</p>
			<p>
				<NcTextField :value.sync="settings['node_binary']" @update:value="onChange" />
			</p>
			<p>{{ t('recognize', 'For Nextcloud Snap users, you need to adjust this path to point to the snap\'s "current" directory as the pre-configured path will change with each update. For example, set it to "/var/snap/nextcloud/current/nextcloud/extra-apps/recognize/bin/node" instead of "/var/snap/nextcloud/9337974/nextcloud/extra-apps/recognize/bin/node"') }}</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'FFMPEG')">
			<p v-if="ffmpeg === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking FFmpeg') }}
			</p>
			<NcNoteCard v-else-if="ffmpeg === false" type="warning">
				{{ t('recognize', 'Could not execute the FFmpeg binary. You may need to set the path to a working binary manually.') }}
			</NcNoteCard>
			<NcNoteCard v-else type="success">
				{{ t('recognize', 'FFmpeg {version} binary was installed successfully.', { version: ffmpeg }) }}
			</NcNoteCard>
			<p>
				{{ t('recognize', 'If the shipped FFmpeg binary doesn\'t work on your system for some reason you can set the path to a custom FFmpeg binary.') }}
			</p>
			<p>
				<NcTextField :value.sync="settings['ffmpeg_binary']" @update:value="onChange" />
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Classifier process priority')">
			<p v-if="nice === undefined">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking Nice binary') }}
			</p>
			<NcNoteCard v-else-if="nice === false" type="warning">
				{{ t('recognize', 'Could not find the Nice binary. You may need to set the path to a working binary manually.') }}
			</NcNoteCard>
			<p>
				{{ t('recognize', 'Nice binary path') }}
			</p>
			<p>
				<NcTextField :value.sync="settings['nice_binary']"
					:label-visible="true"
					:label="t('recognize', 'Nice binary path')"
					@update:value="onChange" />
			</p>
			<p>&nbsp;</p>
			<p>
				{{ t('recognize', 'Nice value to set the priority of the Node.js processes. The value can only be from 0 to 19 since the Node.js process runs without superuser privileges. The higher the nice value, the lower the priority of the process.') }}
			</p>
			<p>
				<NcTextField :value.sync="settings['nice_value']"
					type="number"
					:min="0"
					:max="19"
					:step="1"
					:disabled="nice === false"
					@update:value="onChange" />
			</p>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Fork updates')">
			<p>{{ t('recognize', 'This Recognize and the matching Memories are installed from the CristianCasapu forks on GitHub instead of the app store, so the app store cannot update them. New releases are detected here (and in Administration › Overview); an update downloads the release tarball, swaps the app folder, keeps the downloaded runtime files and runs the app upgrade — as a background job, or with "occ recognize:self-update <app>".') }}</p>
			<p v-if="forkUpdates === null">
				<span class="icon-loading-small" />&nbsp;&nbsp;&nbsp;&nbsp;{{ t('recognize', 'Checking GitHub releases') }}
			</p>
			<template v-else>
				<table class="recognize-table">
					<thead>
						<tr>
							<th>{{ t('recognize', 'App') }}</th>
							<th>{{ t('recognize', 'Installed') }}</th>
							<th>{{ t('recognize', 'Latest release') }}</th>
							<th>{{ t('recognize', 'Published') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(info, app) in forkUpdates.apps" :key="app">
							<td>{{ app }}</td>
							<td>{{ info.installedTag }}</td>
							<td>{{ info.latestTag || '-' }}</td>
							<td>{{ info.published ? info.published.substring(0, 10) : '-' }}</td>
							<td>
								<span v-if="info.error">{{ info.error }}</span>
								<span v-else-if="info.git">{{ t('recognize', 'git checkout — update with git') }}</span>
								<button v-else-if="info.available"
									class="button primary"
									:disabled="updateBusy || (forkUpdates.log && forkUpdates.log.running)"
									@click="onForkUpdate(app)">
									{{ t('recognize', 'Update to {tag}', { tag: info.latestTag }) }}
								</button>
								<span v-else>{{ t('recognize', 'up to date') }}</span>
							</td>
						</tr>
					</tbody>
				</table>
				<div v-if="forkUpdates.log" class="recognize-output-box">
					<strong>{{ forkUpdates.log.running ? t('recognize', 'Update in progress…') : (forkUpdates.log.ok ? t('recognize', 'Update finished') : t('recognize', 'Update failed')) }}</strong>
					<pre class="recognize-output">{{ (forkUpdates.log.lines || []).slice(-15).join('\n') }}</pre>
				</div>
				<button class="button" :disabled="updateBusy" @click="getForkUpdates(true)">
					{{ t('recognize', 'Check again') }}
				</button>
				<p>
					<NcCheckboxRadioSwitch :checked.sync="settings['forkUpdates.auto']" type="switch" @update:checked="onChange">
						{{ t('recognize', 'Install new releases automatically (checked twice a day by the maintenance job; git checkouts are skipped)') }}
					</NcCheckboxRadioSwitch>
				</p>
			</template>
		</NcSettingsSection>
		<NcSettingsSection :name="t('recognize', 'Terminal commands') ">
			<p>{{ t('recognize', 'To download all models preliminary to executing the classification jobs, run the following command on the server terminal.') }}</p>
			<pre><code>occ recognize:download-models</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To trigger a full classification run, run the following command on the server terminal. (The classification will run in multiple background jobs which can run in parallel.)') }}</p>
			<pre><code>occ recognize:recrawl</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To run a full classification run on the terminal, run the following. (The classification will run in sequence inside your terminal.)') }}</p>
			<pre><code>occ recognize:classify</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'Before running a full initial classification run on the terminal, you should stop all background processing that Recognize scheduled upon installation to avoid interference.') }}</p>
			<pre><code>occ recognize:clear-background-jobs</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To run a face clustering run on for each user in the terminal, run the following. Consider adding the parameter --batch-size 10000 for large libraries to avoid PHP memory exhaustion. (The clustering will run in sequence inside your terminal.)') }}</p>
			<pre><code>occ recognize:cluster-faces</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To remove all face clusters but keep the raw detected faces run the following on the terminal:') }}</p>
			<pre><code>occ recognize:reset-face-clusters</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To remove all detected faces and face clusters run the following on the terminal:') }}</p>
			<pre><code>occ recognize:reset-faces</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'You can reset the tags of all files that have been previously classified by Recognize with the following command:') }}</p>
			<pre><code>occ recognize:reset-tags</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'You can delete all tags that no longer have any files associated with them with the following command:') }}</p>
			<pre><code>occ recognize:cleanup-tags</code></pre>
			<p>&nbsp;</p>
			<p>{{ t('recognize', 'To remove tags that were created by Recognize version 2 from all files run the following on the terminal:') }}</p>
			<pre><code>occ recognize:remove-legacy-tags</code></pre>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcNoteCard, NcSettingsSection, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import humanizeDuration from 'humanize-duration'

const SETTINGS = ['tensorflow.cores', 'tensorflow.gpu', 'tensorflow.purejs', 'tensorflow.ldLibraryPath', 'python_binary', 'imagenet.enabled', 'landmarks.enabled', 'faces.enabled', 'musicnn.enabled', 'movinet.enabled', 'node_binary', 'ffmpeg_binary', 'faces.status', 'imagenet.status', 'landmarks.status', 'movinet.status', 'musicnn.status', 'faces.lastFile', 'imagenet.lastFile', 'landmarks.lastFile', 'movinet.lastFile', 'musicnn.lastFile', 'faces.batchSize', 'imagenet.batchSize', 'landmarks.batchSize', 'movinet.batchSize', 'musicnn.batchSize', 'faces.previewDimension', 'faces.tiling', 'faces.minDetectionSize', 'faces.autoMergeThreshold', 'faces.subjectThreshold', 'clusterFaces.status', 'clusterFaces.lastRun', 'nice_binary', 'nice_value', 'concurrency.enabled', 'notifications.enabled', 'forkUpdates.auto', 'faces.autoInstallInsightface', 'clip.enabled', 'clip.status', 'clip.lastFile', 'clip.batchSize', 'clip.model', 'clip.minScore']

const BOOLEAN_SETTINGS = ['tensorflow.gpu', 'tensorflow.purejs', 'imagenet.enabled', 'landmarks.enabled', 'faces.enabled', 'musicnn.enabled', 'movinet.enabled', 'faces.status', 'imagenet.status', 'landmarks.status', 'movinet.status', 'musicnn.status', 'faces.lastFile', 'imagenet.lastFile', 'landmarks.lastFile', 'movinet.lastFile', 'musicnn.lastFile', 'clusterFaces.status', 'concurrency.enabled', 'faces.tiling', 'notifications.enabled', 'forkUpdates.auto', 'faces.autoInstallInsightface', 'clip.enabled', 'clip.status', 'clip.lastFile']

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard, NcCheckboxRadioSwitch, NcTextField },

	data() {
		return {
			faceProgress: null,
			loading: false,
			success: false,
			error: '',
			count: -1,
			countQueued: null,
			settings: SETTINGS.reduce((obj, key) => ({ ...obj, [key]: '' }), {}),
			timeout: null,
			avx: undefined,
			platform: undefined,
			musl: undefined,
			nice: undefined,
			nodejs: undefined,
			libtensorflow: undefined,
			wasmtensorflow: undefined,
			gputensorflow: undefined,
			ffmpeg: undefined,
			cron: undefined,
			modelsDownloaded: null,
			imagenetJobs: null,
			facesJobs: null,
			landmarksJobs: null,
			movinetJobs: null,
			musicnnJobs: null,
			clusterFacesJobs: null,
			clipJobs: null,
			tagsEnabled: null,
			errors: [],
			tensorflow: undefined,
			faceBackend: null,
			backendBusy: false,
			forkUpdates: null,
			updatePoll: null,
			updateBusy: false,
			clip: null,
			clipPoll: null,
			clipBusy: false,
			clipQuery: '',
			clipResults: null,
			installPoll: null,
			mergeSuggestions: null,
			mergeResult: null,
			mergeBusy: false,
			installBusy: false,
			installMessages: [],
		}
	},

	computed: {
		pureJSReasons() {
			const reasons = []
			if (!this.avx) {
				reasons.push(this.t('recognize', 'Your server does not support AVX instructions'))
			}
			if (this.platform !== 'x86_64') {
				reasons.push(this.t('recognize', 'Your server does not have an x86 64-bit CPU'))
			}
			if (this.musl) {
				reasons.push(this.t('recognize', 'Your server uses musl libc'))
			}
			return reasons
		},
	},

	watch: {
		error(error) {
			if (!error) return
			OC.Notification.showTemporary(error)
		},
	},
	async created() {
		this.modelsDownloaded = loadState('recognize', 'modelsDownloaded')
		this.tagsEnabled = loadState('recognize', 'tagsEnabled')
		this.getCount()
		this.getAVX()
		this.getPlatform()
		this.getMusl()
		this.getNice()
		this.getNodejsStatus()
		this.getFfmpegStatus()
		this.getLibtensorflowStatus()
		this.getWasmtensorflowStatus()
		this.getGputensorflowStatus()
		this.getCronStatus()
		this.getErrors()
		this.getTensorflowStatus()
		this.getFaceBackendStatus()
		this.getForkUpdates()
		this.getClipStatus()
		this.getJobsStatus('clip')
		this.getJobsStatus('imagenet')
		this.getJobsStatus('faces')
		this.getJobsStatus('landmarks')
		this.getJobsStatus('movinet')
		this.getJobsStatus('musicnn')
		this.getJobsStatus('clusterFaces')
		this.getFaceProgress()
		setInterval(() => this.getFaceProgress(), 10000)

		setInterval(async () => {
			this.getCount()
			this.getErrors()
			this.loadValue('imagenet.status')
			this.loadValue('faces.status')
			this.loadValue('landmarks.status')
			this.loadValue('movinet.status')
			this.loadValue('musicnn.status')
			this.loadValue('clusterFaces.status')
			this.getJobsStatus('imagenet')
			this.getJobsStatus('faces')
			this.getJobsStatus('landmarks')
			this.getJobsStatus('movinet')
			this.getJobsStatus('musicnn')
			this.getJobsStatus('clusterFaces')
		}, 5 * 60 * 1000)

		try {
			const settings = loadState('recognize', 'settings')
			for (const setting of SETTINGS) {
				this.settings[setting] = settings[setting]
				if (BOOLEAN_SETTINGS.includes(setting)) {
					this.settings[setting] = JSON.parse(this.settings[setting])
				}
				if (setting.includes('batchSize') && (this.settings[setting] === '' || settings[setting] === 'null')) {
					this.settings[setting] = 100
				}
				if (setting === 'tensorflow.cores' && this.settings[setting] === '') {
					this.settings[setting] = 0
				}
			}
		} catch (e) {
			this.error = this.t('recognize', 'Failed to load settings')
			throw e
		}
	},

	methods: {
		async onReset() {
			this.loading = true
			await axios.get(generateUrl('/apps/recognize/admin/reset'))
			await this.getCount()
			this.loading = false
			this.success = true
			setTimeout(() => {
				this.success = false
			}, 3000)
		},
		async onClearJobs() {
			this.loading = true
			await axios.get(generateUrl('/apps/recognize/admin/clearJobs'))
			await this.getCount()
			this.loading = false
			this.success = true
			setTimeout(() => {
				this.success = false
			}, 3000)
		},
		async onResetFaces() {
			const confirmed = await new Promise((resolve) => {
				OC.dialogs.confirm(
					this.t('recognize', 'This removes ALL detected faces and people. Every photo has to be scanned again (hours on a large library). The names you gave to people are saved now and restored automatically after the photos have been scanned and clustered again; manual face tags are lost. Continue?'),
					this.t('recognize', 'Reset all faces'),
					resolve,
					true,
				)
			})
			if (!confirmed) {
				return
			}
			this.loading = true
			await axios.get(generateUrl('/apps/recognize/admin/resetFaces'))
			this.loading = false
			this.success = true
			setTimeout(() => {
				this.success = false
			}, 3000)
		},
		async onRescan() {
			this.loading = true
			await axios.get(generateUrl('/apps/recognize/admin/recrawl'))
			this.loading = false
			this.success = true
			setTimeout(() => {
				this.success = false
			}, 3000)
		},
		async getFaceProgress() {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/faces/progress'))
				this.faceProgress = resp.data
			} catch (e) {
				console.error(e)
			}
		},
		async getCount() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/countQueued'))
			this.countQueued = resp.data
		},
		async getAVX() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/avx'))
			const { avx } = resp.data
			this.avx = avx
		},
		async getPlatform() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/platform'))
			const { platform } = resp.data
			this.platform = platform
		},
		async getNice() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/nice'))
			const { nice } = resp.data
			this.nice = nice
		},
		async getMusl() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/musl'))
			const { musl } = resp.data
			this.musl = musl
		},
		async getNodejsStatus() {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/nodejs'))
				const { nodejs } = resp.data
				// Anything but a version string means we could not determine the version
				this.nodejs = typeof nodejs === 'string' && nodejs !== '' ? nodejs : false
			} catch (e) {
				console.error(e)
				this.nodejs = false
			}
		},
		async getFfmpegStatus() {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/ffmpeg'))
				const { ffmpeg } = resp.data
				// Anything but a version string means we could not determine the version
				this.ffmpeg = typeof ffmpeg === 'string' && ffmpeg !== '' ? ffmpeg : false
			} catch (e) {
				console.error(e)
				this.ffmpeg = false
			}
		},
		async getLibtensorflowStatus() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/libtensorflow'))
			const { libtensorflow } = resp.data
			this.libtensorflow = libtensorflow
		},
		async getWasmtensorflowStatus() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/wasmtensorflow'))
			const { wasmtensorflow } = resp.data
			this.wasmtensorflow = wasmtensorflow
		},
		async getGputensorflowStatus() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/gputensorflow'))
			const { gputensorflow } = resp.data
			this.gputensorflow = gputensorflow
		},
		async getCronStatus() {
			const resp = await axios.get(generateUrl('/apps/recognize/admin/cron'))
			const { cron } = resp.data
			this.cron = cron
		},
		async getErrors() {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/errors'))
				this.errors = resp.data.errors
			} catch (e) {
				console.error(e)
			}
		},
		async clearErrors() {
			await axios.delete(generateUrl('/apps/recognize/admin/errors'))
			this.errors = []
		},
		async getTensorflowStatus() {
			this.tensorflow = undefined
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/tensorflow'))
				this.tensorflow = resp.data
			} catch (e) {
				console.error(e)
				this.tensorflow = { ok: false, mode: '', missingLibraries: [], output: String(e) }
			}
		},
		async onInstallDeps() {
			this.installBusy = true
			this.installMessages = []
			try {
				const resp = await axios.post(generateUrl('/apps/recognize/admin/installDeps'))
				this.installMessages = resp.data.messages
			} catch (e) {
				console.error(e)
				this.installMessages = (e.response && e.response.data && e.response.data.messages) || [String(e)]
				this.error = this.t('recognize', 'Re-installing the dependencies failed')
			} finally {
				this.installBusy = false
			}
			this.getNodejsStatus()
			this.getFfmpegStatus()
			this.getLibtensorflowStatus()
			this.getTensorflowStatus()
		},
		async getFaceBackendStatus(refresh = false) {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/faces/backend'), { params: { refresh: refresh ? 1 : 0 } })
				this.faceBackend = resp.data
				const running = resp.data.install && resp.data.install.running
				if (running && !this.installPoll) {
					this.installPoll = setInterval(() => this.getFaceBackendStatus(), 10000)
				} else if (!running && this.installPoll) {
					clearInterval(this.installPoll)
					this.installPoll = null
					this.getFaceBackendStatus(true)
				}
			} catch (e) {
				console.error(e)
			}
		},
		async onInstallInsightface() {
			this.backendBusy = true
			try {
				await axios.post(generateUrl('/apps/recognize/admin/faces/installInsightface'), { gpu: this.settings['tensorflow.gpu'] === true })
				await this.getFaceBackendStatus()
			} catch (e) {
				console.error(e)
				this.error = this.t('recognize', 'Could not schedule the InsightFace installation')
			} finally {
				this.backendBusy = false
			}
		},
		async onSwitchFaceBackend(backend) {
			const confirmed = await new Promise((resolve) => {
				OC.dialogs.confirm(
					this.t('recognize', 'Switching the face model removes all detected faces and face clusters, because the face descriptors of the two models are incompatible. The names you gave to people are saved and restored automatically after the photos have been scanned and clustered again. Continue and switch to "{backend}"?', { backend }),
					this.t('recognize', 'Switch face model'),
					resolve,
					true,
				)
			})
			if (!confirmed) {
				return
			}
			this.backendBusy = true
			try {
				const resp = await axios.post(generateUrl('/apps/recognize/admin/faces/backend'), { backend })
				this.success = true
				setTimeout(() => {
					this.success = false
				}, 3000)
				if (resp.data.snapshot) {
					OC.Notification.showTemporary(this.t('recognize', 'Saved {names} person names; a new face scan has been scheduled.', { names: resp.data.snapshot.titles }))
				}
				await this.getFaceBackendStatus()
				await this.getCount()
			} catch (e) {
				console.error(e)
				this.error = (e.response && e.response.data && e.response.data.message) || this.t('recognize', 'Switching the face model failed')
			} finally {
				this.backendBusy = false
			}
		},
		async getClipStatus() {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/clip'))
				this.clip = resp.data
				const running = resp.data.install && resp.data.install.running
				if (running && !this.clipPoll) {
					this.clipPoll = setInterval(() => this.getClipStatus(), 10000)
				} else if (!running && this.clipPoll) {
					clearInterval(this.clipPoll)
					this.clipPoll = null
					this.loadValue('clip.enabled')
				}
			} catch (e) {
				console.error(e)
			}
		},
		async onInstallClip() {
			this.clipBusy = true
			try {
				await axios.post(generateUrl('/apps/recognize/admin/clip/install'))
				await this.getClipStatus()
			} catch (e) {
				console.error(e)
				this.error = this.t('recognize', 'Could not schedule the model download')
			} finally {
				this.clipBusy = false
			}
		},
		async onClipTest() {
			if (!this.clipQuery) {
				return
			}
			this.clipBusy = true
			this.clipResults = null
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/clip/test'), { params: { q: this.clipQuery, limit: 8 } })
				this.clipResults = resp.data.results
			} catch (e) {
				console.error(e)
				this.error = (e.response && e.response.data && e.response.data.message) || this.t('recognize', 'Search failed')
			} finally {
				this.clipBusy = false
			}
		},
		async getForkUpdates(refresh = false) {
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/updates'), { params: { refresh: refresh ? 1 : 0 } })
				this.forkUpdates = resp.data
				const running = resp.data.log && resp.data.log.running
				if (running && !this.updatePoll) {
					this.updatePoll = setInterval(() => this.getForkUpdates(), 10000)
				} else if (!running && this.updatePoll) {
					clearInterval(this.updatePoll)
					this.updatePoll = null
					this.getForkUpdates(true)
				}
			} catch (e) {
				console.error(e)
			}
		},
		async onForkUpdate(app) {
			this.updateBusy = true
			try {
				await axios.post(generateUrl(`/apps/recognize/admin/updates/${app}`))
				await this.getForkUpdates()
			} catch (e) {
				console.error(e)
				this.error = this.t('recognize', 'Could not schedule the update')
			} finally {
				this.updateBusy = false
			}
		},
		async getMergeSuggestions() {
			this.mergeBusy = true
			this.mergeResult = null
			try {
				const resp = await axios.get(generateUrl('/apps/recognize/admin/faces/mergeSuggestions'))
				this.mergeSuggestions = resp.data
			} catch (e) {
				console.error(e)
				this.error = this.t('recognize', 'Failed to compute merge candidates')
			} finally {
				this.mergeBusy = false
			}
		},
		async onAutoMerge() {
			this.mergeBusy = true
			try {
				const resp = await axios.post(generateUrl('/apps/recognize/admin/faces/autoMerge'))
				this.mergeResult = resp.data
				this.mergeSuggestions = null
				this.getCount()
			} catch (e) {
				console.error(e)
				this.error = this.t('recognize', 'Merging clusters failed')
			} finally {
				this.mergeBusy = false
			}
		},
		mergeCandidates(suggestions) {
			return Object.entries(suggestions.users).flatMap(([user, candidates]) => candidates.map(c => ({ user, ...c })))
		},
		async getJobsStatus(task) {
			const resp = await axios.get(generateUrl(`/apps/recognize/admin/jobs/${task}`))
			const { scheduled, lastRun } = resp.data
			this[task + 'Jobs'] = { scheduled, lastRun }
		},
		onChange() {
			if (this.timeout) {
				clearTimeout(this.timeout)
			}
			setTimeout(() => {
				this.submit()
			}, 1000)
		},

		async submit() {
			this.loading = true
			for (const setting in this.settings) {
				if (setting.includes('status') || setting.includes('lastFile')) {
					continue
				}
				await this.setValue(setting, this.settings[setting])
			}
			this.loading = false
			this.success = true
			setTimeout(() => {
				this.success = false
			}, 3000)
		},

		async loadValue(setting) {
			this.settings[setting] = await this.getValue(setting)
			if (BOOLEAN_SETTINGS.includes(setting)) {
				this.settings[setting] = JSON.parse(this.settings[setting])
			}
		},
		async setValue(setting, value) {
			try {
				if (BOOLEAN_SETTINGS.includes(setting)) {
					value = JSON.stringify(value)
				}
				await axios.put(generateUrl(`/apps/recognize/admin/settings/${setting}`), {
					value,
				})
			} catch (e) {
				this.error = this.t('recognize', 'Failed to save settings')
				throw e
			}
		},

		async getValue(setting) {
			try {
				const res = await axios.get(generateUrl(`/apps/recognize/admin/settings/${setting}`))
				if (res.status !== 200) {
					this.error = this.t('recognize', 'Failed to load settings')
					console.error('Failed request', res)
					return
				}
				return res.data.value
			} catch (e) {
				this.error = this.t('recognize', 'Failed to load settings')
				throw e
			}
		},

		showDate(timestamp) {
			if (!timestamp) {
				return this.t('recognize', 'never')
			}
			const date = new Date(Number(timestamp) * 1000)
			const age = Date.now() - date
			if (age < MAX_RELATIVE_DATE) {
				const duration = humanizeDuration(age, {
					language: OC.getLanguage().split('-')[0],
					units: ['d', 'h', 'm', 's'],
					largest: 1,
					round: true,
				})
				return this.t('recognize', '{time} ago', { time: duration })
			} else {
				return date.toLocaleDateString()
			}
		},
	},
}
</script>
<style>
figure[class^='icon-'] {
	display: inline-block;
}

#recognize {
	position: relative;
}

#recognize .loading,
#recognize .success {
	position: fixed;
	top: 70px;
	right: 20px;
}

#recognize a:link, #recognize a:visited, #recognize a:hover {
	text-decoration: underline;
}

#recognize .recognize-errors {
	list-style: disc;
	margin: 8px 0 8px 20px;
	max-height: 300px;
	overflow: auto;
}

#recognize .recognize-errors li {
	margin-bottom: 4px;
	word-break: break-word;
}

#recognize .recognize-output {
	max-height: 300px;
	overflow: auto;
	white-space: pre-wrap;
	font-size: 12px;
}

#recognize .recognize-table {
	border-collapse: collapse;
	margin: 8px 0;
}

#recognize .recognize-table th, #recognize .recognize-table td {
	border-bottom: 1px solid var(--color-border);
	padding: 4px 8px;
	text-align: left;
}

#recognize h3 {
	font-weight: bold;
	margin-top: 8px;
}

#recognize .recognize-output-box {
	margin: 8px 0;
}
</style>
