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
			<h3>{{ t('recognize', 'Automatic merging of clusters') }}</h3>
			<p>{{ t('recognize', 'Clustering runs in batches and never merges two existing clusters, so the same person often ends up as one named and several unnamed clusters. When a threshold above 0 is set, unnamed clusters whose average face is closer than the threshold to a named cluster (and clearly farther from every other named cluster) are merged into it after every clustering run. Distances between different people are usually above 0.45; 0.4 is a safe start. Use "Preview" to see what would be merged with the current threshold before enabling it.') }}</p>
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
							<td>{{ candidate.mergeable ? t('recognize', 'yes') : t('recognize', 'no') }}</td>
						</tr>
					</tbody>
				</table>
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
			<p>{{ t('recognize', 'Click the button below to remove all face detections from all files that have been classified so far.') }}</p>
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

const SETTINGS = ['tensorflow.cores', 'tensorflow.gpu', 'tensorflow.purejs', 'tensorflow.ldLibraryPath', 'imagenet.enabled', 'landmarks.enabled', 'faces.enabled', 'musicnn.enabled', 'movinet.enabled', 'node_binary', 'ffmpeg_binary', 'faces.status', 'imagenet.status', 'landmarks.status', 'movinet.status', 'musicnn.status', 'faces.lastFile', 'imagenet.lastFile', 'landmarks.lastFile', 'movinet.lastFile', 'musicnn.lastFile', 'faces.batchSize', 'imagenet.batchSize', 'landmarks.batchSize', 'movinet.batchSize', 'musicnn.batchSize', 'faces.previewDimension', 'faces.tiling', 'faces.minDetectionSize', 'faces.autoMergeThreshold', 'clusterFaces.status', 'clusterFaces.lastRun', 'nice_binary', 'nice_value', 'concurrency.enabled', 'notifications.enabled']

const BOOLEAN_SETTINGS = ['tensorflow.gpu', 'tensorflow.purejs', 'imagenet.enabled', 'landmarks.enabled', 'faces.enabled', 'musicnn.enabled', 'movinet.enabled', 'faces.status', 'imagenet.status', 'landmarks.status', 'movinet.status', 'musicnn.status', 'faces.lastFile', 'imagenet.lastFile', 'landmarks.lastFile', 'movinet.lastFile', 'musicnn.lastFile', 'clusterFaces.status', 'concurrency.enabled', 'faces.tiling', 'notifications.enabled']

const MAX_RELATIVE_DATE = 1000 * 60 * 60 * 24 * 7 // one week

export default {
	name: 'ViewAdmin',
	components: { NcSettingsSection, NcNoteCard, NcCheckboxRadioSwitch, NcTextField },

	data() {
		return {
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
			tagsEnabled: null,
			errors: [],
			tensorflow: undefined,
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
		this.getJobsStatus('imagenet')
		this.getJobsStatus('faces')
		this.getJobsStatus('landmarks')
		this.getJobsStatus('movinet')
		this.getJobsStatus('musicnn')
		this.getJobsStatus('clusterFaces')

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
</style>
