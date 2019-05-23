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
	<ul>
		<!-- If no link shares, show the add link default entry -->
		<SharingEntryLink v-if="hasShares" :file-info="fileInfo" @add:share="addShare" />

		<!-- Else we display the list -->
		<template v-else>
			<SharingEntryLink v-for="share in shares" :share="share" :key="share.id" :file-info="fileInfo"
				@remove:share="removeShare" @add:share="addShare" />
		</template>
	</ul>
</template>

<script>
import SharingEntryLink from '../components/SharingEntryLink'

export default {
	name: 'SharingLinkList',

	components: {
		SharingEntryLink
	},

	props: {
		fileInfo: {
			type: Object,
			default: () => {},
			required: true
		},
		shares: {
			type: Array,
			default: () => [],
			required: true
		}
	},

	computed: {
		hasShares() {
			return this.shares.length === 0
		}
	},

	methods: {
		/**
		 * Add a new share into the link shares list
		 */
		addShare(share) {
			this.shares.push(share)
		},

		removeShare(id) {
			const index = this.shares.findIndex(share => share.id === id)
			this.shares.splice(index, 1)
		}
	}
}
</script>
