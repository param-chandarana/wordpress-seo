/**
 * Imagemin config: replaces the @yoast/grunt-plugin-tasks imagemin config with correct SVGO v2
 * syntax and adds a target for packages/js/images/.
 *
 * The upstream plugin target uses SVGO v1 plugin syntax which is silently ignored by imagemin-svgo
 * v7 (SVGO v2). This replaces it with preset-default, which applies the full SVGO optimisation
 * suite and produces smaller output. The js-images target extends the same optimisation to SVGs
 * under packages/js/images/.
 */

const svgoOptions = {
	svgoPlugins: [
		{ name: "preset-default" },
		{
			name: "addAttributesToSVGElement",
			params: {
				attributes: [
					{ role: "img" },
					{ "aria-hidden": "true" },
					{ focusable: "false" },
				],
			},
		},
	],
};

module.exports = {
	plugin: {
		options: svgoOptions,
		files: [
			{
				expand: true,
				cwd: "<%= paths.images %>",
				src: [ "*.*" ],
				dest: "<%= paths.images %>",
				isFile: true,
			},
			{
				expand: true,
				cwd: "<%= paths.assets %>",
				src: [ "*.*" ],
				dest: "<%= paths.assets %>",
				isFile: true,
			},
		],
	},
	"js-images": {
		options: svgoOptions,
		files: [
			{
				expand: true,
				cwd: "packages/js/images/",
				src: [ "*.svg" ],
				dest: "packages/js/images/",
				isFile: true,
			},
		],
	},
};
