const path = require('path');
const webpack = require('webpack');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = {
  mode: 'production',
  entry: path.resolve(
    __dirname,
    'js/ckeditor5_plugins/snippet_schema/src/index.js',
  ),
  output: {
    path: path.resolve(__dirname, 'js/build'),
    filename: 'snippet-schema.js',
    library: ['CKEditor5', 'genehubSnippet'],
    libraryTarget: 'umd',
    libraryExport: 'default',
  },
  optimization: {
    minimize: true,
    minimizer: [
      new TerserPlugin({
        terserOptions: {
          format: {
            comments: false,
          },
        },
        extractComments: false,
      }),
    ],
    moduleIds: 'named',
  },
  plugins: [
    new webpack.optimize.LimitChunkCountPlugin({
      maxChunks: 1,
    }),
    new webpack.DllReferencePlugin({
      manifest: require('./node_modules/ckeditor5/build/ckeditor5-dll.manifest.json'),
      scope: 'ckeditor5/src',
      name: 'CKEditor5.dll',
    }),
  ],
};
