<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { mdiFolderOutline } from '@mdi/js'
import { View, getNavigation } from '@nextcloud/files'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getTeamSpaceContents } from '../services/teamSpace.ts'

const props = defineProps<{
	folderId: number
	mountPoint: string
}>()

const container = ref<HTMLElement>()
let rendered: RenderedFilesApp | null = null

const folderIcon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiFolderOutline}" /></svg>`

/** Register the team's scoped view (if needed) and embed the Files file list into the container. */
function mountFilesApp(): void {
	if (!container.value) {
		return
	}

	const viewId = `teamspace-${props.folderId}`
	const navigation = getNavigation()
	if (!navigation.views.some((view) => view.id === viewId)) {
		navigation.register(new View({
			id: viewId,
			name: props.mountPoint,
			icon: folderIcon,
			getContents: getTeamSpaceContents(props.mountPoint),
		}))
	}

	rendered = window.OCP.Files.renderFilesApp(container.value, viewId)
}

/** Tear down the embedded file list and its scoped view. */
function unmountFilesApp(): void {
	rendered?.destroy()
	rendered = null
	getNavigation().remove(`teamspace-${props.folderId}`)
}

onMounted(mountFilesApp)
onBeforeUnmount(unmountFilesApp)
watch(() => props.folderId, () => {
	unmountFilesApp()
	mountFilesApp()
})
</script>

<template>
	<div ref="container" class="team-files-embed" />
</template>

<style lang="scss" scoped>
.team-files-embed {
	height: 100%;
}
</style>
