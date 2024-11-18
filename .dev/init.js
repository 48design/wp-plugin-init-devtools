#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');
const { execSync } = require('child_process');
const { globSync } = require('glob');

// Repository to clone (placeholder for username)
let GIT_REPO = "https://{username}@github.com/48design/wp-plugin-init.git";
let USED_USERNAME = ""; // Track the username used

// Paths
const scriptDir = __dirname;
const currentDir = process.cwd();
let pluginBaseDir = currentDir;

// Determine the correct plugins directory
if (currentDir.endsWith(path.join('wp-content', 'plugins'))) {
  pluginBaseDir = currentDir;
} else if (currentDir.endsWith('wp-content')) {
  // If in wp-content, check for plugins directory
  const potentialPluginsDir = path.join(currentDir, 'plugins');
  if (fs.existsSync(potentialPluginsDir)) {
    pluginBaseDir = potentialPluginsDir;
  }
} else if (fs.existsSync(path.join(currentDir, 'wp-content', 'plugins'))) {
  // If in WordPress root, use wp-content/plugins
  pluginBaseDir = path.join(currentDir, 'wp-content', 'plugins');
} else {
  console.log("Execute me in or near your WordPress instance's wp-content/plugins folder!");
  process.exit(1);
}

// Log and check requirements
console.log("Checking requirements...");
const requirements = [
  { name: 'PHP available', check: () => commandExists('php') },
  { name: 'PHP version >= 8.0', check: () => phpVersionAtLeast('8.0') },
  { name: 'Git available', check: () => commandExists('git') },
  { name: 'Repository access', check: checkRepositoryAccess }
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
    const pluginPath = path.join(pluginBaseDir, slug);

    if (fs.existsSync(pluginPath)) {
      console.log(`A plugin with the slug "${slug}" already exists. Please choose a different slug.`);
      return askForSlug(pluginName, defaultSlug);
    }

    askForDescription(pluginName, slug);
  });
}

function askForDescription(pluginName, slug) {
  rl.question("Enter a short description for your plugin: ", (description) => {
    const pluginPath = path.join(pluginBaseDir, slug);
    setupPlugin(pluginName, slug, description, pluginPath);
    rl.close();
  });
}

function setupPlugin(pluginName, slug, description, pluginPath) {
  console.log("Cloning repository...");
  try {
    const cloneRepo = GIT_REPO.replace("{username}", USED_USERNAME || "");
    execSync(`git clone --depth=1 ${cloneRepo} ${pluginPath}`, { stdio: 'inherit' });

    // If no username was determined earlier, extract it from the .git/config file
    if (!USED_USERNAME) {
      USED_USERNAME = getGitUsernameFromConfig(pluginPath);
      console.log(`Detected username from .git/config: ${USED_USERNAME}`);
    }

    console.log(`Repository successfully cloned using username: ${USED_USERNAME || "credential manager"}`);

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
      packageJson.version = "1.0.0";
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

    // Remove all .gitkeep files recursively using globSync
    removeGitkeepFiles(pluginPath);

    console.log(`Plugin "${pluginName}" created successfully at ${pluginPath}`);
  } catch (err) {
    console.error("Error setting up the plugin:", err.message);
    if (fs.existsSync(pluginPath)) {
      fs.rmSync(pluginPath, { recursive: true, force: true });
    }
  }
}

function checkRepositoryAccess() {
  const defaultUser = getGitHubUser();
  const testUsers = [
    "48design",
    defaultUser,
    "" // No username, fallback to credential manager
  ];

  for (const user of testUsers) {
    try {
      const url = GIT_REPO.replace("{username}", user);

      // Build the Git command
      let gitCommand = `ls-remote ${url}`;
      let options = { stdio: 'ignore', env: { ...process.env } };
      if (user) {
        // Suppress credential popups but allow silent use of stored credentials
        gitCommand = `-c credential.interactive=Never ${gitCommand}`;
        options.env.GIT_TERMINAL_PROMPT = '0';
      }

      // console.log(`Testing repository access with user: ${user || "credential manager"}`);
      // console.log(gitCommand);
      execSync(`git ${gitCommand}`, options);

      // Success: Set the repository URL and username
      GIT_REPO = url;
      USED_USERNAME = user;
      // console.log(`Repository access successful with user: ${user || "credential manager"}`);
      return true;
    } catch {
      // console.log(`Repository access failed with user: ${user || "credential manager"}`);
      // Continue to the next URL
    }
  }

  console.error("Could not access the repository using any credentials.");
  return false;
}


function getGitUsernameFromConfig(pluginPath) {
  const configPath = path.join(pluginPath, '.git', 'config');
  try {
    const config = fs.readFileSync(configPath, 'utf8');
    const match = config.match(/url = https:\/\/(.*?)@github.com/);
    return match ? match[1] : ''; // Extract username from URL
  } catch {
    return '';
  }
}

function getGitHubUser() {
  try {
    return execSync('git config --get user.name', { encoding: 'utf8' }).trim();
  } catch {
    return ''; // Return an empty string if no username is configured
  }
}

function removeGitkeepFiles(dir) {
  const files = globSync(`${dir}/**/.gitkeep`);
  files.forEach(file => fs.rmSync(file));
}

function commandExists(cmd) {
  try {
    execSync(`${cmd} --version`, { stdio: 'ignore' });
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
