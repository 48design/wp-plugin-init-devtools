#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');
const { execSync } = require('child_process');
const glob = require('glob');

// Repository to clone (with username for access to private repo)
const GIT_REPO = "https://48design@github.com/48design/wp-plugin-init.git";

// Paths
const scriptDir = __dirname;
const currentDir = process.cwd();

// Check if the script is in the correct directory
if (!currentDir.endsWith(path.join('wp-content', 'plugins'))) {
  const locationMessage =
    scriptDir === currentDir
      ? "Execute me in your dev WordPress instance's wp-content/plugins folder!"
      : "Ensure you're executing me in your dev WordPress instance's wp-content/plugins folder!";
  console.log(locationMessage);
  process.exit(1);
}

// Log and check requirements
console.log("Checking requirements");
const requirements = [
  { name: 'PHP available', check: () => commandExists('php') },
  { name: 'PHP version >= 8.0', check: () => phpVersionAtLeast('8.0') },
  { name: 'Git available', check: () => commandExists('git') },
  { name: 'Node.js available', check: () => commandExists('node') },
  { name: 'npm available', check: () => commandExists('npm') }
];

const results = requirements.map(req => `[${req.check() ? 'X' : ' '}] ${req.name}`);
results.forEach(result => console.log(result));

if (results.some(r => r.includes('[ ]'))) {
  console.log("Not all requirements are met. Exiting.");
  process.exit(1);
}

// Create the readline interface
const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout
});

// Main function to handle input and plugin creation
function createPlugin() {
  rl.question("What is the name of your new WordPress plugin? ", (input) => {
    const defaultSlug = input.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z-]/g, '');
    askForSlug(input, defaultSlug);
  });
}

function askForSlug(pluginName, defaultSlug) {
  rl.question(`Plugin slug (default: ${defaultSlug}): `, (slugInput) => {
    const slug = slugInput.trim() || defaultSlug;
    const pluginPath = path.join(currentDir, slug);

    if (fs.existsSync(pluginPath)) {
      console.log(`A plugin with the slug "${slug}" already exists. Please choose a different slug.`);
      return askForSlug(pluginName, defaultSlug);
    }

    askForDescription(pluginName, slug);
  });
}

function askForDescription(pluginName, slug) {
  rl.question("Enter a short description for your plugin: ", (description) => {
    const pluginPath = path.join(currentDir, slug);
    setupPlugin(pluginName, slug, description, pluginPath);
    rl.close();
  });
}

function setupPlugin(pluginName, slug, description, pluginPath) {
  console.log("Cloning repository...");
  try {
    // Clone the repository
    execSync(`git clone --depth=1 ${GIT_REPO} ${pluginPath}`, { stdio: 'inherit' });

    // Install dependencies
    console.log("Installing dependencies...");
    execSync(`npm install`, { cwd: pluginPath, stdio: 'inherit' });

    // Remove the .git folder
    fs.rmSync(path.join(pluginPath, '.git'), { recursive: true, force: true });

    // Remove unnecessary files
    const initFilePath = path.join(pluginPath, '.dev', 'init.js');
    if (fs.existsSync(initFilePath)) fs.rmSync(initFilePath);

    const readmeMdPath = path.join(pluginPath, 'README.md');
    if (fs.existsSync(readmeMdPath)) fs.rmSync(readmeMdPath);

    // Rename the main plugin file to match the slug
    const mainFilePath = path.join(pluginPath, `${slug}.php`);
    const defaultMainFile = path.join(pluginPath, 'plugin.php');
    if (fs.existsSync(defaultMainFile)) fs.renameSync(defaultMainFile, mainFilePath);

    // Update the main plugin file with the provided details
    fs.writeFileSync(mainFilePath, generatePluginHeader(pluginName, slug, description));

    // Update the package.json
    const packageJsonPath = path.join(pluginPath, 'package.json');
    if (fs.existsSync(packageJsonPath)) {
      let packageJson = JSON.parse(fs.readFileSync(packageJsonPath, 'utf8'));
      packageJson.name = slug;
      packageJson.description = description;
      packageJson.author = "48DESIGN GmbH";
      packageJson.version = "1.0.0"; // Set version
      delete packageJson.bin; // Remove the "bin" property
      fs.writeFileSync(packageJsonPath, JSON.stringify(packageJson, null, 2));
    }

    // Update the readme.txt file
    const readmeTxtPath = path.join(pluginPath, 'readme.txt');
    if (fs.existsSync(readmeTxtPath)) {
      let readmeContent = fs.readFileSync(readmeTxtPath, 'utf8');
      readmeContent = readmeContent.replace(/{Plugin Name}/g, pluginName).replace(/{Description Text}/g, description);
      fs.writeFileSync(readmeTxtPath, readmeContent);
    }

    // Remove all .gitkeep files recursively using glob
    removeGitkeepFiles(pluginPath);

    console.log(`Plugin "${pluginName}" created successfully at ${pluginPath}`);
  } catch (err) {
    console.error("Error setting up the plugin:", err.message);
    if (fs.existsSync(pluginPath)) {
      fs.rmSync(pluginPath, { recursive: true, force: true }); // Clean up in case of failure
    }
  }
}

function removeGitkeepFiles(dir) {
  glob(`${dir}/**/.gitkeep`, (err, files) => {
    if (err) {
      console.error("Error finding .gitkeep files:", err.message);
      return;
    }
    files.forEach(file => {
      fs.rmSync(file);
    });
  });
}

// Helper functions
function commandExists(cmd) {
  try {
    execSync(`${cmd} --version`, { stdio: 'ignore' }); // Try running the command
    return true;
  } catch {
    return false;
  }
}

function phpVersionAtLeast(requiredVersion) {
  try {
    const output = execSync('php -v', { encoding: 'utf8' });
    const version = output.match(/\d+\.\d+\.\d+/)?.[0];
    return version && compareVersions(version, requiredVersion) >= 0;
  } catch {
    return false;
  }
}

function compareVersions(v1, v2) {
  const [a, b, c] = v1.split('.').map(Number);
  const [x, y, z] = v2.split('.').map(Number);
  return a - x || b - y || c - z;
}

function generatePluginHeader(pluginName, slug, description) {
  return `<?php
/*
Plugin Name: ${pluginName}
Plugin URI: https://48design.com
Description: ${description}
Version: 1.0.0
Author: 48DESIGN GmbH
Author URI: https://48design.com
Text Domain: ${slug}
*/
`;
}

// Start the script
createPlugin();
