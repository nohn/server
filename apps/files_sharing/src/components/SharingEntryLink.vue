<!--
  - @copyright Copyright (c) 2019 John Molakvoæ <skjnldsv@protonmail.com>
  -
  - @author John Molakvoæ <skjnldsv@protonmail.com>
  -
  - @license GNU AGPL version 3 or any later version
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
  -->

<template>
	<li :class="{'sharing-entry--share': share}" class="sharing-entry">
		<Avatar :isNoUser="true" class="sharing-entry__avatar icon-public-white"></Avatar>
		<div class="sharing-entry__desc">
			<h4>{{ title }}</h4>
		</div>

		<!-- clipboard -->
		<Actions ref="copyButton" v-if="share" :disable-tooltip="true" class="sharing-entry__copy" v-tooltip.auto="{
			// make sure to manually show the tooltip aagain after click
			// as it will take away the focus and close the tooltip
			show: copied,
			content: clipboardTooltip,
			trigger: copied ? 'manual' : 'hover'
		}">
			<ActionLink :href="shareLink" target="_blank" icon="icon-clippy" @click.stop.prevent="copyLink"></ActionLink>
		</Actions>

		<!-- actions -->
		<Actions v-if="!loading" class="sharing-entry__actions" menu-align="right" :open.sync="open">
			<!-- pending data menu -->
			<template v-if="pendingPassword || pendingExpirationDate">
					<!-- password -->
				<ActionText v-if="pendingPassword" icon="icon-password">
					{{ t('files_sharing', 'Enter a password') }}
				</ActionText>
				<ActionInput v-if="pendingPassword"
					:value.sync="share.password" icon=""
					@submit="checkNewShare" @update:value="checkNewShare" />

					<!-- expiration date -->
				<ActionText v-if="pendingExpirationDate" icon="icon-calendar-dark">
					{{ t('files_sharing', 'Select an expiration date') }}
				</ActionText>
				<ActionInput v-if="pendingExpirationDate"
					:value.sync="share.expireDate"
					type="date"
					icon=""></ActionInput>
			</template>

			<!-- global menu -->
			<template v-else>
				<template v-if="share">
					<!-- folder -->
					<template v-if="isFolder && share.hasCreatePermission && this.config.isPublicUploadEnabled">
						<ActionCheckbox @change="togglePermissions">{{ t('files_sharing', 'Read only') }}</ActionCheckbox>
						<ActionCheckbox @change="togglePermissions">{{ t('files_sharing', 'Allow upload and editing') }}</ActionCheckbox>
						<ActionCheckbox @change="togglePermissions">{{ t('files_sharing', 'File drop (upload only)') }}</ActionCheckbox>
					</template>

					<!-- file -->
					<ActionCheckbox v-else
						:checked.sync="canUpdate" 
						@change="queueUpdate('permissions')">{{ t('files_sharing', 'Allow editing') }}</ActionCheckbox>

					<ActionCheckbox 
						:checked.sync="share.hideDownload"
						@change="queueUpdate('hideDownload')">
						{{ t('files_sharing', 'Hide download') }}
					</ActionCheckbox>

					<!-- password -->
					<ActionCheckbox :checked.sync="isPasswordProtected"
						@uncheck="queueUpdate('password')">
						{{ t('files_sharing', 'Password Protect') }}
					</ActionCheckbox>
					<ActionInput v-if="isPasswordProtected"
						:value.sync="share.password"
						icon="icon-password"
						@update:value="queueUpdate('password')" />

					<!-- expiration date -->
					<ActionCheckbox :checked.sync="hasExpirationDate"
						@uncheck="queueUpdate('expireDate')">
						{{ t('files_sharing', 'Set expiration date') }}
					</ActionCheckbox>
					<ActionInput v-if="hasExpirationDate"
						:value="share.expireDate"
						icon="icon-calendar-dark"
						type="date"
						@update:value="onExpirationChange" />
					
					<!-- note -->
					<ActionCheckbox :checked.sync="hasNote"
						@uncheck="queueUpdate('note')">
						{{ t('files_sharing', 'Note to recipient') }}
					</ActionCheckbox>
					<ActionTextEditable v-if="hasNote"
						:value.sync="share.note"
						icon="icon-edit"
						@update:value="queueUpdate('note')" />

					<ActionButton icon="icon-delete" @click.prevent="onDelete">{{ t('files_sharing', 'Delete share link') }}</ActionButton>
					<ActionButton class="new-share-link" icon="icon-add" @click.prevent="newLinkShare">{{ t('files_sharing', 'Add another link') }}</ActionButton>
				</template>

				<!-- Create new share -->
				<ActionButton v-else class="new-share-link" icon="icon-add" @click.prevent="newLinkShare">{{ t('files_sharing', 'Create a new share link') }}</ActionButton>
			</template>
		</Actions>

		<!-- loading indicator to replace the menu -->
		<div v-else class="icon-loading-small sharing-entry__loading"></div>
	</li>
</template>

<script>
import { generateOcsUrl, generateUrl } from 'nextcloud-router/dist/index'
import axios from 'nextcloud-axios'
import PQueue from 'p-queue'
import debounce from 'debounce'

import ActionButton from 'nextcloud-vue/dist/Components/ActionButton'
import ActionCheckbox from 'nextcloud-vue/dist/Components/ActionCheckbox'
import ActionInput from 'nextcloud-vue/dist/Components/ActionInput'
import ActionText from 'nextcloud-vue/dist/Components/ActionText'
import ActionTextEditable from 'nextcloud-vue/dist/Components/ActionTextEditable'
import ActionLink from 'nextcloud-vue/dist/Components/ActionLink'
import Actions from 'nextcloud-vue/dist/Components/Actions'
import Avatar from 'nextcloud-vue/dist/Components/Avatar'
import Tooltip from 'nextcloud-vue/dist/Directives/Tooltip'

import Config from '../services/ConfigService'
import Share from '../models/Share';
import SharesMixin from '../mixins/SharesMixin'

const updateQueue = new PQueue({ concurrency: 1 })

export default {
	name: 'SharingEntryLink',

	components: {
		Actions,
		ActionButton,
		ActionCheckbox,
		ActionInput,
		ActionLink,
		ActionText,
		ActionTextEditable,
		Avatar
	},

	mixins: [SharesMixin],

	directives: {
		Tooltip
	},

	props: {
		fileInfo: {
			type: Object,
			default: () => {},
			required: true
		},
		share: {
			type: Share,
			default: null
		}
	},

	data() {
		return {
			config: new Config(),
			copySuccess: true,
			copied: false,
			loading: false,
			open: false,
			/**
			 * ! This allow vue to make the Share class state reactive
			 * ! do not remove it ot you'll lose all reactivity here
			 */
			reactiveState: this.share && this.share.state
		}
	},

	computed: {

		/**
		 * Link share label
		 * TODO: allow editing
		 */
		title() {
			if (this.share && this.share.label && this.share.label.trim() !== '') {
				return this.share.label
			}
			return t('files_sharing', 'Share link')
		},

		/**
		 * Pending data.
		 * If the share still doesn't have an id, it is not synced
		 * Therefore this is still not valid
		 */
		pendingPassword() {
			return this.config.enforcePasswordForPublicLink && this.share && !this.share.id
		},
		pendingExpirationDate() {
			return this.config.isDefaultExpireDateEnforced && this.share && !this.share.id
		},

		/**
		 * Can the recipient edit the file ?
		 * @returns {boolean}
		 */
		canUpdate: {
			get: function() {
				return this.share.hasUpdatePermission
			},
			set: function(enabled) {
				this.share.permissions = enabled
					? OC.PERMISSION_READ | OC.PERMISSION_UPDATE
					: OC.PERMISSION_READ
			}
		},

		/**
		 * Is the current share password protected ?
		 * @returns {boolean}
		 */
		isPasswordProtected: {
			get: function() {
				return !!this.share.password
			},
			set: function(enabled) {
				// TODO: directly save after generation to make sure the share is always protected
				this.share.password = enabled ? this.generatePassword() : ''
			}
		},

		/**
		 * Does the current share have an expiration date
		 * @returns {boolean}
		 */
		hasExpirationDate: {
			get: function() {
				return !!this.share.expireDate
			},
			set: function(enabled) {
				this.share.expireDate = enabled
					? this.config.defaultExpirationDateString !== ''
						? this.config.defaultExpirationDateString
						: moment().format('YYYY-MM-DD')
					: ''
			}
		},

		/**
		 * Does the current share have a note
		 * @returns {boolean}
		 */
		hasNote: {
			get: function() {
				return !!this.share.note
			},
			set: function(enabled) {
				this.share.note = enabled
					? t('files_sharing', 'Enter a note for the share recipient')
					: ''
			}
		},

		/**
		 * Is the current share a folder ?
		 * @returns {boolean}
		 */
		isFolder() {
			return this.share.type === 'folder'
		},

		/** 
		 * Return the public share link
		 * @returns {string}
		 */
		shareLink() {
			return window.location.protocol + '//' + window.location.host + generateUrl('/s/') + this.share.token
		},

		/**
		 * Clipboard v-tooltip message 
		 * @returns {string}
		 */
		clipboardTooltip() {
			if (this.copied) {
				return this.copySuccess
					? t('files_sharing', 'Link copied')
					: t('files_sharing', 'Cannot copy, please copy the link manually')
			}
			return t('files_sharing', 'Copy to clipboard')
		}
	},

	mounted() {
		// if share is a newly created one, copy the url directly
		if (this.share && this.share.created) {
			this.copyLink()
		}
	},

	methods: {
		/**
		 * Create a new share link and append it to the list
		 */
		async newLinkShare() {
			// do not push yet if we need a password or an expiration date
			if (this.config.enforcePasswordForPublicLink || this.config.isDefaultExpireDateEnforced) {
				const shareDefaults = {}
				if (this.config.enforcePasswordForPublicLink) {
					shareDefaults.password = this.generatePassword()
				}
				if (this.config.isDefaultExpireDateEnforced) {
					// default is empty string if not set
					shareDefaults.expireDate = this.config.defaultExpirationDateString
				}

				// create share & close menu
				this.$emit('add:share', new Share(shareDefaults))
				this.open = false
			
			// Nothing enforced, creating share directly
			} else {
				try {
					this.loading = true
					this.open = false
					const path = this.fileInfo.path + this.fileInfo.name
					const share = await this.createShare({
						path,
						shareType: OC.Share.SHARE_TYPE_LINK
					})

					// mark the share as freshly created
					share.created = true
					console.debug('Link share created', share);
					this.$emit('add:share', share)
				} catch(error) {
					// re-open menu if error
					this.open = true
				} finally {
					this.loading = false
				}
			}
		},

		async onDelete() {
			try {
				this.loading = true
				this.open = false
				await this.deleteShare(this.share.id)
				console.debug('Link share deleted', this.share.id);
				this.$emit('remove:share', this.share.id)
			} catch(error) {
				// re-open menu if error
				this.open = true
			} finally {
				this.loading = false
			}
		},

		togglePermissions(data) {
			console.info(data);
		},

		generatePassword() {
			// ! TODO add default password / generate with password policy
			return 'password'
		},

		// TODO: REMOVE
		log(a) {
			console.info(a);
		},
		
		async copyLink() {
			try {
				await this.$copyText(this.shareLink)
				this.copySuccess = true
				this.copied = true
			} catch (error) {
				this.copySuccess = false
				this.copied = true
				console.error(error);
			} finally {
				setTimeout(() => {
					this.copySuccess = false
					this.copied = false
				}, 4000)
			}
		},

		/**
		 * ActionInput can be a little tricky to work with.
		 * Since we expect a string and not a Date,
		 * we need to process the value here
		 */
		onExpirationChange(date) {
			// format to YYYY-MM-DD
			const value = date.toISOString().split('T')[0]
			this.share.expireDate = value
			this.queueUpdate('expireDate')
		},

		/**
		 * Send an update of the share to the queue
		 * Debounced to avoid requests spamming (more importantly for text data) 
		 */
		queueUpdate: debounce(function(property) {
			const value = this.share[property]
			updateQueue.add(() => this.updateShare(this.share.id, {
				property,
				value
			}))
		}, 500)
	}

}
</script>
  
<style lang="scss" scoped>
.sharing-entry {
	display: flex;
	align-items: center;
	height: 44px;
	&__desc {
		display: flex;
		flex-direction: column;
		justify-content: space-between;
		padding: 8px;
		line-height: 1.2em;
	}

	&:not(.sharing-entry--share) &__actions {
		margin-left: auto;
		.new-share-link {
			border-top: 1px solid var(--color-border);
		}
	}

	&__copy {
		margin-left: auto
	}

	&__loading {
		width: 44px;
		height: 44px;
		margin: 0;
		padding: 14px;
		margin-left: auto;
	}
}
</style>
