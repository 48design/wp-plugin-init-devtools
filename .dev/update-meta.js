const fs = require('fs');
const path = require('path');

// Get the current working directory
const cwd = process.cwd();

// Load the package version
const packageJson = require(path.join(cwd, 'package.json'));
const packageVersion = packageJson.version;
const pluginShorthand = packageJson?.['wp-plugin']?.shorthand;
const pluginSlug = packageJson?.['wp-plugin']?.slug;

console.log(`Set version to ${packageVersion}...`);

// Paths to target files
const mainFile = path.join(cwd, `${pluginSlug}.php`);
const readmeFile = path.join(cwd, 'readme.txt');

// Update main plugin file
console.log('=> ' + mainFile);
const indexContent = fs.readFileSync(mainFile, 'utf8');
fs.writeFileSync(
  mainFile,
  indexContent
    .replace(/^( \* Version: )[\d.]+(\-[a-z0-9]+)*$/m, `$1${packageVersion}`)
    .replace(new RegExp(`(define\\(\\s*['"]WP_${pluginShorthand}_VERSION['"],\\s*['"])[^'"]+(['"]\\s*\\);)`), `$1${packageVersion}$2`)
);

// Update readme.txt
console.log('=> ' + readmeFile);
const readmeContent = fs.readFileSync(readmeFile, 'utf8');
const testedMatch = indexContent.match(/\*\s*Tested up to:\s*(.+)/);
const requiresMatch = indexContent.match(/\*\s*Requires at least:\s*(.+)/);

fs.writeFileSync(
  readmeFile,
  readmeContent
    .replace(/^Tested up to: .+/m, `Tested up to: ${testedMatch ? testedMatch[1] : ''}`)
    .replace(/^Requires at least: .+/m, `Requires at least: ${requiresMatch ? requiresMatch[1] : ''}`)
    .replace(/^Stable tag: .+/m, `Stable tag: ${packageVersion}`)
);

console.log('DONE.');
