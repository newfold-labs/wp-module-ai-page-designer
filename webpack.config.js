const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
  entry: './src/index.tsx',
  output: {
    path: path.resolve(__dirname, 'build'),
    filename: 'index.js',
  },
  module: {
    rules: [
      {
        test: /\.(ts|tsx)$/,
        exclude: /node_modules/,
        use: {
          loader: 'ts-loader',
        },
      },
      {
        test: /\.css$/,
        use: [MiniCssExtractPlugin.loader, 'css-loader'],
      },
      {
        test: /\.(woff2|woff)$/i,
        type: 'asset/resource',
        generator: {
          filename: 'fonts/[name][ext]',
        },
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: 'index.css',
    }),
  ],
  resolve: {
    extensions: ['.tsx', '.ts', '.js', '.jsx'],
  },
  // @wordpress/interface is bundled (no wp-interface core script handle —
  // see WorkspaceShell.tsx), but everything IT depends on internally must be
  // externalized against the same core globals wp-admin already loads.
  // Otherwise our bundle registers its own copy of e.g. the `core/preferences`
  // @wordpress/data store on top of the one core already registered, which
  // throws "Store ... is already registered" at runtime.
  externals: {
    'react': 'React',
    'react-dom': 'ReactDOM',
    'react-dom/client': 'ReactDOM',
    '@wordpress/api-fetch': 'wp.apiFetch',
    '@wordpress/a11y': 'wp.a11y',
    '@wordpress/components': 'wp.components',
    '@wordpress/compose': 'wp.compose',
    '@wordpress/data': 'wp.data',
    '@wordpress/deprecated': 'wp.deprecated',
    '@wordpress/element': 'wp.element',
    '@wordpress/i18n': 'wp.i18n',
    '@wordpress/plugins': 'wp.plugins',
    '@wordpress/preferences': 'wp.preferences',
    '@wordpress/viewport': 'wp.viewport',
    // @wordpress/icons is deliberately NOT externalized — there is no
    // `wp-icons` core script handle (confirmed against
    // wp-includes/assets/script-loader-packages.php). Declaring a PHP
    // script dependency on a handle that doesn't exist makes WordPress
    // silently drop the whole script from the enqueue queue, so this one
    // stays bundled.
  },
};
