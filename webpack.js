const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

module.exports = webpackConfig
webpackConfig.entry.admin = path.join(__dirname, 'src', 'admin.js')

// @vueuse/core (pulled in by @nextcloud/vue 8) imports Vue 3 only symbols such as
// `Fragment` from vue-demi. They are never called with Vue 2.7, but webpack treats the
// missing ESM exports as build errors, so only warn about them for that package.
webpackConfig.module.rules.push({
	test: /node_modules[\\/]@vueuse[\\/]core[\\/]/,
	parser: { exportsPresence: 'warn' },
})
