/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.ts',
    './resources/**/*.tsx',
    './resources/photobook-editor/**/*.{ts,tsx,html,php}',
  ],
  theme: { extend: {} },
  plugins: [],
}
