/**
 * Imagemin config: adds a target for packages/js/images/ with full SVGO optimization.
 *
 * The @yoast/grunt-plugin-tasks imagemin config only targets the root images/ and svn-assets/
 * folders. This adds a js-images target for packages/js/images/ that is picked up automatically
 * when the imagemin task runs, applying the accessibility attributes required by Yoast's SVG usage.
 */
module.exports = {
	"js-images": {
		options: {
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
		},
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
