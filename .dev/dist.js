const rimraf = require("rimraf");
const glob = require('glob').globSync;
const path = require('path')
const fs = require('fs')
const fse = require('fs-extra')
const archiver = require('archiver')
const cwd = process.cwd();
const { spawn, execSync } = require('child_process');
const package = require(path.resolve(cwd, 'package.json'));

// const isAlpha = /-alpha$/.test(package.name);

const removeIfEmpty = [
  './assets',
];

let targetCount = 0;
let finishedTargets = 0;

let DEBUG_KEEP_FILES = true;

function escapeRegex(string) {
  return string.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&')
}

function resolveFilePlaceholders( stringIn, target ) {
  return stringIn.replace(/\{target\}/g, target.name)
}

function modifyContent(files, basePath, target) {
  files.forEach(file => {
    const copiedFile = file.path.replace( new RegExp(`^${ basePath }`), '' )

    if (!copiedFile.startsWith(vendorPath)) { // we don't want to touch any vendor files
      const pathParts = [ 'dist', '_temp', target.name ]
      const relativeFilePath = path.relative(path.resolve( ...[fs.realpathSync(cwd), pathParts].flat() ), fs.realpathSync(file.path)).replace(/\\/g, '/')

      if (target.fileBlacklist && target.fileBlacklist.includes(relativeFilePath)) {
        fs.unlinkSync( file.path )
        console.log(`[${target.name}] remove ${relativeFilePath}`)
      } else if (/\.(php|js|css|json|txt|md)$/.test(file.path)) {
        let content = fs.readFileSync(file.path, 'utf8')

        let modifiedContent = content

        let outputFile = file.path
        let newFilename = relativeFilePath

        if (target.renameFiles && target.renameFiles[relativeFilePath] ) {
          newFilename = resolveFilePlaceholders(target.renameFiles[relativeFilePath], target)
          console.log(`[${target.name}] rename ${relativeFilePath} => ${newFilename}`)
          outputFile = path.resolve( path.dirname(outputFile), newFilename )
          fs.unlinkSync( file.path )
        }

        if (target.replaceFileContent && target.replaceFileContent[relativeFilePath]) {
          console.log(`[${target.name}] replace content in ${newFilename}`)
          modifiedContent = target.replaceFileContent[relativeFilePath](modifiedContent)
        }

        if ( modifiedContent !== content || outputFile !== file.path ) {
          fs.writeFileSync(outputFile, modifiedContent)
        }
      } else if (target.renameFiles && target.renameFiles[relativeFilePath] ) {
        let newFilename = resolveFilePlaceholders(target.renameFiles[relativeFilePath], target)
        console.log(`[${target.name}] rename ${relativeFilePath} => ${newFilename}`)
        let outputFile = path.resolve( path.dirname(file.path), newFilename )
        fs.renameSync( file.path, outputFile )
      }
    }
  })
}

function createTarget(target) {
  targetCount++

  if(!target.copyFiles) {
    target.copyFiles = []; // target-specific folder is included automatically below
  }

  const pathParts = [ 'dist', '_temp', `${target.name}` ]
  let files = [ ...target.copyFiles, './' + target.name + '/**' ]; // add target-specific folder

  if ( !!target.includeVendorDir ) {
    files.push( './vendor/**' );
  }

  /**
   * Copies files matching the given glob patterns and calls the callback with an error (if any)
   * and an array of file objects with path, name, and destination properties.
   *
   * @param {string[]} patterns - Array of glob patterns to match files.
   * @param {string} destDir - Destination directory where files will be copied.
   * @param {function} callback - Callback function with (err, files) arguments.
   */
  function copyFilesSync(patterns, destDir, callback) {
    return new Promise((resolve, reject) => {
      const copiedFiles = [];

      try {
        patterns.forEach(pattern => {
          const files = glob(pattern);

          files.forEach(file => {
            const stats = fs.statSync(file);
            if (!stats.isFile()) return; // Skip directories

            const fileName = path.basename(file);
            const relativeDir = path.relative(cwd, path.dirname(file));
            const destPathDir = path.join(destDir, relativeDir);
            const destPath = path.join(destPathDir, fileName);

            fs.mkdirSync(destPathDir, { recursive: true });
            fs.copyFileSync(file, destPath);

            copiedFiles.push({
              path: destPath,  // Adjusted to use the destination path
              name: fileName,
              relativePath: path.relative(destDir, destPath),
              destination: destPath
            });
          });
        });

        callback(null, copiedFiles);
        resolve();

      } catch (error) {
        callback(error, copiedFiles);
        reject();
      }
    });
  }

  return copyFilesSync(files, path.join( ...pathParts ), function (err, files) {
    const basePath = escapeRegex(path.join( ...[ cwd, pathParts ].flat() ))

    if (err) {
      console.table( err )
    } else {
      console.log(`finished copying files to target ${target.name}`)
      modifyContent(files, basePath, target)
      console.log(`finished modifying files for target ${target.name}`)
      // remove configured folders if empty
      removeEmptyFolders(removeIfEmpty, path.join(...pathParts));
    }
  })
}

function removeEmptyFolders(folders, destDir) {
  folders.forEach(folder => {
    // Normalize the folder path to remove any leading './'
    const sanitizedFolder = folder.replace(/^\.\//, '');
    const folderPath = path.join(destDir, sanitizedFolder);

    const removeIfEmpty = dirPath => {
      const contents = fs.readdirSync(dirPath);

      // Recursively check and remove subdirectories if empty
      contents.forEach(content => {
        const contentPath = path.join(dirPath, content);
        if (fs.lstatSync(contentPath).isDirectory()) {
          removeIfEmpty(contentPath);
        }
      });

      // Remove the directory if it’s now empty
      if (fs.readdirSync(dirPath).length === 0) {
        fs.rmdirSync(dirPath);
        console.log(`Removed empty directory: ${dirPath}`);
      }
    };

    if (fs.existsSync(folderPath)) {
      removeIfEmpty(folderPath);
    }
  });
}

function createArchive(target) {
  const zipName = `dist/${package.name}-${package.version}-${target.name}.zip`
  const output = fs.createWriteStream(zipName)
  const archive = archiver('zip')

  output.on('close', function () {
    console.log(`created ${zipName} (${archive.pointer()} bytes)`)

    if (target.finally) {
      target.finally()
    }

    finishedTargets++

    if (finishedTargets === targetCount) {
      if (!DEBUG_KEEP_FILES) rimraf.sync('dist/_temp')
      console.log("\nDONE.")
    }
  })

  archive.on('error', function(err){
    throw err;
  })

  archive.pipe(output)

  let suffix = '';
  if (target.innerFolderSuffix === true) {
    suffix = `-${target.name}`;
  } else if (target.innerFolderSuffix !== false) {
    suffix = `-${target.innerFolderSuffix}`;
  }

  archive.directory(`dist/_temp/${target.name}`, `${package.name}${suffix}`)
  archive.finalize()
}

const vendorPath = path.join('/', 'vendor', '/')

rimraf.sync('dist/_temp')

const target_free = {
  name: 'free',
  innerFolderSuffix: false,
  copyFiles: [
    'license.txt',
    'readme.txt',
    './*.php',
    './js/**',
    './css/**',
    './assets/**',
    './vendor/**',
    './languages/**',
    './lib/**',
  ],
  renameFiles: {
    'assets/icon.svg': '../../../../svn/assets/icon.svg',
    'assets/banner-772x250.jpg': '../../../../svn/assets/banner-772x250.jpg',
    'assets/banner-1544x500.jpg': '../../../../svn/assets/banner-1544x500.jpg',
    'assets/icon-128x128.png': '../../../../svn/assets/icon-128x128.png',
    'assets/icon-256x256.png': '../../../../svn/assets/icon-256x256.png',
    'assets/screenshot-1.png': '../../../../svn/assets/screenshot-1.png',
    'assets/screenshot-2.png': '../../../../svn/assets/screenshot-2.png',
    'assets/screenshot-3.png': '../../../../svn/assets/screenshot-3.png',
    'assets/screenshot-4.png': '../../../../svn/assets/screenshot-4.png',
    'assets/screenshot-5.png': '../../../../svn/assets/screenshot-5.png',
    'assets/screenshot-6.png': '../../../../svn/assets/screenshot-6.png',
    'assets/screenshot-7.png': '../../../../svn/assets/screenshot-7.png',
  },
  finally() {
    console.log( `[${this.name}] clean SVN trunk` )
    rimraf.sync('./svn/trunk')
    console.log( `[${this.name}] copy contents to SVN trunk` )
    fse.copySync( `./dist/_temp/${this.name}`, './svn/trunk' )
  }
};

const target_premium = {
  name: 'premium',
  innerFolderSuffix: true,
  includeVendorDir: true,
  copyFiles: Object.assign([], target_free.copyFiles, [
    // premium folder is being copied automatically
  ]),
  fileBlacklist: [
    'premium/vad-updater/README.md',
  ],
  renameFiles: {
    [`${package.name}.php`]: `${package.name}-{target}.php`
  },
  replaceFileContent: {
    'premium/vad-updater/package.json': ( content ) => {
      let newContent = JSON.parse(content);
      newContent = JSON.stringify({
        name: newContent.name,
        version: newContent.version,
        author: newContent.author,
        license: newContent.license,
      }, null, 2);
      return newContent
    }
  },
};

const targets = [
  target_free,
  target_premium,
];

const targetPromises = targets.map((target) => createTarget(target));
Promise.all(targetPromises).then(async () => {
  const phpcompatScriptPath = path.join('.dev', 'vendor', 'bin', 'phpcompatinfo');
  const wpvcScriptPath = path.join('.dev', 'wp-versioncheck', 'wpvc.php');

  /**
   * get minimum PHP version
   *
   */
  const phpcompatPhpArgs = [
    '-n',
    'analyser:run',
  ];
  const phpcompatPromises = targets.map((target) => {
    return (new Promise((resolve, reject) => {
      const targetDir = path.join(cwd, 'dist', '_temp', target.name);
      const phpcompatArgs = [phpcompatScriptPath, ...phpcompatPhpArgs, targetDir];
      const phpProcess = spawn('php', phpcompatArgs);

      console.log(`\n> php ${phpcompatArgs.join(' ')}`);

      phpProcess.stdout.on('data', (data) => {
        // Convert Buffer to string to process \r correctly
        const output = data.toString();
        const matches = output.match(/\[OK\] Requires PHP ([0-9a-z-.]+)/i);
        if(matches) {
          const phpMinVer = matches[1];
          process.stdout.write(`\n[${target.name}] Minimum required PHP version: ${phpMinVer}`);

          const command = `php ${wpvcScriptPath} --update-files-only --php "${phpMinVer}" "${targetDir}"`;
          process.stdout.write(`\n> ${command}`);
          const output = execSync(command, { encoding: 'utf8' });
          process.stdout.write(`\n${output}`);
        }
      });

      phpProcess.on('close', (code) => {
        if(code !== 0) {
          console.log(`ERROR: PHP exited with code ${code}`);
          process.exit(1);
        } else {
          resolve();
        }
      });
    }))
  });

  console.log("\nWaiting for PHP compatibility checks to complete...");

  await Promise.all(phpcompatPromises);

  /**
   * get minimum WordPress version
   */
  const wpvcPhpArgs = [
    '--update-files'
  ];
  const targetDirs = targets.map(target => path.join(cwd, 'dist', '_temp', target.name));
  const wpvcArgs = [wpvcScriptPath, ...wpvcPhpArgs, ...targetDirs];

  console.log(`\n\n> php ${wpvcArgs.join(' ')}\n`);

  const phpProcess = spawn('php', wpvcArgs);

  phpProcess.stdout.on('data', (data) => {
    // Convert Buffer to string to process \r correctly
    const output = data.toString();
    process.stdout.write(output);
  });

  // Listen for errors
  phpProcess.stderr.on('data', (data) => {
    console.error(`PHP Error: ${data}`);
  });

  phpProcess.on('close', (code) => {
    process.stdout.write("\r\n");

    if(code !== 0) {
      console.log(`PHP exited with code ${code}`);
    } else {
      targets.forEach((target) => createArchive(target));
    }
  });

});

